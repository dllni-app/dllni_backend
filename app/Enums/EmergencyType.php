<?php

declare(strict_types=1);

namespace App\Enums;

enum EmergencyType: string
{
    case SafetyThreat = 'safety_threat';
    case MedicalEmergency = 'medical_emergency';
    case SevereConflict = 'severe_conflict';
}
