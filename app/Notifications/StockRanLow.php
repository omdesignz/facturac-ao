<?php

namespace App\Notifications;

use App\Models\CatalogueItem;
use App\Models\Establishment;
use App\NotificationTopic;

class StockRanLow extends WorkspaceNotification
{
    public function __construct(
        public readonly string $itemCode,
        public readonly string $itemName,
        public readonly string $establishmentName,
        public readonly string $quantity,
        public readonly string $reorderLevel,
        public readonly string $unitOfMeasure,
        public readonly int $workspace,
    ) {
        parent::__construct();
    }

    public static function fromLevel(
        CatalogueItem $item,
        Establishment $establishment,
        int $quantityUnits,
        int $reorderUnits,
    ): self {
        $scale = 10 ** $item->stock_scale;

        return new self(
            itemCode: $item->code,
            itemName: $item->name,
            establishmentName: $establishment->name,
            quantity: number_format($quantityUnits / $scale, $item->stock_scale, ',', ' '),
            reorderLevel: number_format($reorderUnits / $scale, $item->stock_scale, ',', ' '),
            unitOfMeasure: $item->unit_of_measure,
            workspace: $item->workspace_id,
        );
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::StockLow;
    }

    public function title(): string
    {
        return "{$this->itemName} no nível de reposição";
    }

    public function body(): string
    {
        return "{$this->establishmentName}: restam {$this->quantity} {$this->unitOfMeasure} "
            ."(mínimo {$this->reorderLevel}).";
    }

    public function url(): ?string
    {
        return route('stock.index', ['search' => $this->itemCode]);
    }

    public function workspaceId(): ?int
    {
        return $this->workspace;
    }

    /**
     * One notice per article and place until it is restocked.
     *
     * Selling five more of something already below the line is not five pieces
     * of news.
     */
    public function dedupeKey(): ?string
    {
        return "stock_low:{$this->itemCode}:{$this->establishmentName}";
    }
}
