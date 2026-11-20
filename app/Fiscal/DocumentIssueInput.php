<?php

namespace App\Fiscal;

use App\FiscalDocumentType;
use App\PaymentMethod;
use Normalizer;

/**
 * A document an integration asks to issue: an invoice (FT), an invoice-receipt
 * (FR) or a credit note (NC) against an earlier invoice.
 *
 * Only what the caller may decide is accepted. Prices and taxes of catalogue
 * lines, and everything a credit note copies from its invoice, are read on the
 * server; the expected total is how the caller confirms what it will be billed.
 *
 * @phpstan-type SaleLine array{catalogue_item_public_id?: string, quantity: string, discount_percentage?: string, description?: string, unit_price_minor?: int, tax_treatment?: string, service?: bool}
 * @phpstan-type NoteLine array{line_number: int, quantity: string}
 */
final readonly class DocumentIssueInput implements \JsonSerializable
{
    public const int MAXIMUM_LINES = 200;

    public const int MAXIMUM_MINOR = 999_999_999_999_999;

    private const string ID = '/\A[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}\z/';

    private const string QUANTITY = '/\A(?:0|[1-9]\d{0,14})(?:\.\d{1,4})?\z/';

    private const string DISCOUNT = '/\A(?:100(?:\.0{1,2})?|(?:0|[1-9]\d?)(?:\.\d{1,2})?)\z/';

    /** @param array<string, mixed> $attributes */
    private function __construct(public array $attributes, public FiscalDocumentType $type) {}

    public static function external(#[\SensitiveParameter] string $json): self
    {
        $values = StrictCommandJson::object($json);
        $errors = [];
        $type = is_string($values['document_type'] ?? null) ? FiscalDocumentType::tryFrom($values['document_type']) : null;
        if (! in_array($type, [FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt, FiscalDocumentType::CreditNote], true)) {
            throw CommandRefused::invalid(['document_type' => 'Indique FT, FR ou NC.']);
        }
        $note = $type === FiscalDocumentType::CreditNote;
        $allowed = $note
            ? ['document_type', 'references_document_public_id', 'reason', 'lines', 'expected_total_minor']
            : ['document_type', 'customer_public_id', 'payment_method', 'notes', 'lines', 'expected_total_minor'];
        foreach (array_diff(array_keys($values), $allowed) as $unknown) {
            $errors[(string) $unknown] = 'Este campo não é aceite neste documento.';
        }

        $attributes = ['document_type' => $type->value];
        if ($note) {
            $attributes['references_document_public_id'] = self::id($values, 'references_document_public_id', true, $errors);
            $reason = self::text($values, 'reason', 5, 60, $errors);
            if ($reason === null && ! isset($errors['reason'])) {
                $errors['reason'] = 'Diga porque corrige a factura.';
            }
            $attributes['reason'] = $reason;
        } else {
            $attributes['customer_public_id'] = self::id($values, 'customer_public_id', $type === FiscalDocumentType::Invoice, $errors);
            $attributes['payment_method'] = null;
            if ($type === FiscalDocumentType::InvoiceReceipt) {
                $method = is_string($values['payment_method'] ?? null) ? PaymentMethod::tryFrom($values['payment_method']) : null;
                if ($method === null) {
                    $errors['payment_method'] = 'Indique o meio de pagamento (NU, CD, CC, TB, …).';
                }
                $attributes['payment_method'] = $method?->value;
            } elseif (array_key_exists('payment_method', $values)) {
                $errors['payment_method'] = 'Só uma factura-recibo leva meio de pagamento.';
            }
            $attributes['notes'] = self::text($values, 'notes', 1, 1000, $errors, multiline: true);
        }

        $lines = $values['lines'] ?? null;
        if (! is_array($lines) || ! array_is_list($lines) || $lines === [] || count($lines) > self::MAXIMUM_LINES) {
            $errors['lines'] = 'Envie entre 1 e '.self::MAXIMUM_LINES.' linhas.';
            $lines = [];
        }
        $attributes['lines'] = [];
        foreach ($lines as $index => $line) {
            $attributes['lines'][] = $note ? self::noteLine($line, $index, $errors) : self::saleLine($line, $index, $errors);
        }
        if ($note) {
            $numbers = array_filter(array_column($attributes['lines'], 'line_number'));
            if (count($numbers) !== count(array_unique($numbers))) {
                $errors['lines'] = 'Cada linha da factura só pode aparecer uma vez.';
            }
        }

        $total = $values['expected_total_minor'] ?? null;
        if (! is_string($total) || ! ServiceCreateInput::validMinor($total) || (int) $total < 1 || (int) $total > self::MAXIMUM_MINOR) {
            $errors['expected_total_minor'] = 'Indique o total esperado em cêntimos, como texto (por exemplo "22800").';
        }
        $attributes['expected_total_minor'] = $errors === [] ? (int) $total : 0;

        if ($errors !== []) {
            ksort($errors);

            throw CommandRefused::invalid($errors);
        }

        return new self($attributes, $type);
    }

    /**
     * @param  array<string, string>  $errors
     * @return SaleLine
     */
    private static function saleLine(mixed $line, int $index, array &$errors): array
    {
        $at = "lines.$index";
        if (! $line instanceof \stdClass) {
            $errors[$at] = 'Cada linha é um objecto.';

            return ['quantity' => '0'];
        }
        $line = get_object_vars($line);
        $fromCatalogue = array_key_exists('catalogue_item_public_id', $line);
        $allowed = $fromCatalogue
            ? ['catalogue_item_public_id', 'quantity', 'discount_percentage']
            : ['description', 'unit_price_minor', 'tax_treatment', 'service', 'quantity', 'discount_percentage'];
        foreach (array_diff(array_keys($line), $allowed) as $unknown) {
            $errors["$at.$unknown"] = $fromCatalogue
                ? 'Uma linha do catálogo leva só o artigo, a quantidade e o desconto.'
                : 'Este campo não é aceite numa linha.';
        }
        $clean = ['quantity' => self::quantity($line, $at, $errors)];
        if ($fromCatalogue) {
            $clean['catalogue_item_public_id'] = self::id($line, 'catalogue_item_public_id', true, $errors, $at) ?? '';
        } else {
            $description = self::text($line, 'description', 2, 200, $errors, prefix: $at);
            if ($description === null && ! isset($errors["$at.description"])) {
                $errors["$at.description"] = 'Indique catalogue_item_public_id, ou descreva a linha com preço e imposto.';
            }
            $price = $line['unit_price_minor'] ?? null;
            if (! is_string($price) || ! ServiceCreateInput::validMinor($price) || (int) $price < 1 || (int) $price > self::MAXIMUM_MINOR) {
                $errors["$at.unit_price_minor"] = 'Indique o preço unitário em cêntimos, como texto.';
            }
            $treatment = is_string($line['tax_treatment'] ?? null) ? SupportedTaxTreatment::tryFrom($line['tax_treatment']) : null;
            if ($treatment === null) {
                $errors["$at.tax_treatment"] = 'Indique IVA_NOR_14, IVA_ISE_M00, NS_M02 ou IVA_ISE_M04.';
            }
            if (array_key_exists('service', $line) && ! is_bool($line['service'])) {
                $errors["$at.service"] = 'Indique true ou false.';
            }
            $clean += [
                'description' => (string) $description,
                'unit_price_minor' => is_string($price) && ServiceCreateInput::validMinor($price) ? (int) $price : 0,
                'tax_treatment' => $treatment?->value ?? '',
                'service' => ($line['service'] ?? false) === true,
            ];
        }
        if (array_key_exists('discount_percentage', $line)) {
            $discount = $line['discount_percentage'];
            if (! is_string($discount) || preg_match(self::DISCOUNT, $discount) !== 1) {
                $errors["$at.discount_percentage"] = 'O desconto vai de "0" a "100", com até duas casas decimais.';
            } else {
                $clean['discount_percentage'] = $discount;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, string>  $errors
     * @return NoteLine
     */
    private static function noteLine(mixed $line, int $index, array &$errors): array
    {
        $at = "lines.$index";
        if (! $line instanceof \stdClass) {
            $errors[$at] = 'Cada linha é um objecto.';

            return ['line_number' => 0, 'quantity' => '0'];
        }
        $line = get_object_vars($line);
        foreach (array_diff(array_keys($line), ['line_number', 'quantity']) as $unknown) {
            $errors["$at.$unknown"] = 'Uma linha de nota de crédito leva só line_number e quantity.';
        }
        $number = $line['line_number'] ?? null;
        if (! is_int($number) || $number < 1 || $number > 4_294_967_295) {
            $errors["$at.line_number"] = 'Indique o número da linha na factura.';
        }

        return ['line_number' => is_int($number) ? $number : 0, 'quantity' => self::quantity($line, $at, $errors)];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $errors
     */
    private static function quantity(array $values, string $at, array &$errors): string
    {
        $quantity = $values['quantity'] ?? null;
        if (! is_string($quantity) || preg_match(self::QUANTITY, $quantity) !== 1 || (float) $quantity <= 0) {
            $errors["$at.quantity"] = 'A quantidade é texto, superior a zero, com até quatro casas decimais.';

            return '0';
        }

        return $quantity;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $errors
     */
    private static function id(array $values, string $field, bool $required, array &$errors, string $prefix = ''): ?string
    {
        $key = $prefix === '' ? $field : "$prefix.$field";
        if (! array_key_exists($field, $values)) {
            if ($required) {
                $errors[$key] = 'Este campo é obrigatório.';
            }

            return null;
        }
        if (! is_string($values[$field]) || preg_match(self::ID, $values[$field]) !== 1) {
            $errors[$key] = 'Indique um identificador público válido.';

            return null;
        }

        return strtolower($values[$field]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $errors
     */
    private static function text(array $values, string $field, int $minimum, int $maximum, array &$errors, bool $multiline = false, string $prefix = ''): ?string
    {
        $key = $prefix === '' ? $field : "$prefix.$field";
        if (! array_key_exists($field, $values)) {
            return null;
        }
        $value = $values[$field];
        $normalized = is_string($value) ? Normalizer::normalize($value, Normalizer::FORM_C) : false;
        $control = $multiline ? '/[\x{0000}-\x{0009}\x{000b}-\x{001f}\x{007f}-\x{009f}]/u' : '/[\x{0000}-\x{001f}\x{007f}-\x{009f}]/u';
        $trimmed = is_string($normalized) ? trim($normalized, " \t\r\n") : '';
        if (! is_string($normalized) || preg_match($control, str_replace("\r\n", "\n", $trimmed)) !== 0
            || mb_strlen($trimmed) < $minimum || mb_strlen($trimmed) > $maximum) {
            $errors[$key] = "Use texto entre $minimum e $maximum caracteres.";

            return null;
        }

        return $trimmed;
    }

    public function jsonSerialize(): never
    {
        throw new \LogicException('Command data cannot be serialized.');
    }

    public function __serialize(): array
    {
        throw new \LogicException('Command input cannot be serialized.');
    }
}
