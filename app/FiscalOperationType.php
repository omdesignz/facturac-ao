<?php

namespace App;

enum FiscalOperationType: string
{
    case EducationService = 'SE';
    case HealthService = 'SS';
    case PassengerTransport = 'STP';
    case RoyaltiesService = 'SR';
    case FinancialInsuranceIntermediation = 'SIF';
    case HospitalityService = 'SHS';
    case TelecommunicationsService = 'ST';
    case GeneralService = 'SG';
    case GoodsTransfer = 'TB';
    case Rental = 'AS';
    case MembershipFee = 'QT';
    case ExpenseRecharge = 'RD';

    public function label(): string
    {
        return match ($this) {
            self::EducationService => 'Serviço de educação',
            self::HealthService => 'Serviço de saúde',
            self::PassengerTransport => 'Transporte de passageiros',
            self::RoyaltiesService => 'Serviço sujeito a royalties',
            self::FinancialInsuranceIntermediation => 'Intermediação financeira ou seguradora',
            self::HospitalityService => 'Hotelaria e similares',
            self::TelecommunicationsService => 'Telecomunicações',
            self::GeneralService => 'Serviço geral',
            self::GoodsTransfer => 'Transmissão de bens',
            self::Rental => 'Arrendamento ou subarrendamento',
            self::MembershipFee => 'Quotas',
            self::ExpenseRecharge => 'Repasse de despesas',
        };
    }
}
