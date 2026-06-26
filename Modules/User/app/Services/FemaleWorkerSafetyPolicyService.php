<?php

declare(strict_types=1);

namespace Modules\User\Services;

final class FemaleWorkerSafetyPolicyService
{
    public const BENEFICIARY_FEMALE_PRESENT = 'female_present';
    public const BENEFICIARY_MALE_ALONE = 'male_alone';

    public function version(): string
    {
        return 'female-worker-safety-v1';
    }

    public function title(): string
    {
        return 'تأكيد بيئة العمل';
    }

    public function question(): string
    {
        return 'من سيكون متواجداً في الموقع أثناء تقديم الخدمة؟';
    }

    public function blockedMessage(): string
    {
        return 'عذراً، يمنع طلب عاملات إناث في حال تواجد رجال بمفردهم في الموقع لحرصنا على سلامة الطاقم، يمكنك اختيار عامل ذكر.';
    }

    public function pledgeTitle(): string
    {
        return 'شروط السلامة والمسؤولية القانونية';
    }

    public function pledgeText(): string
    {
        return 'أقر أنا المستخدم بأن الموقع مهيأ لاستقبال عاملة أنثى بتواجد نسائي، وأتحمل كامل المسؤولية القانونية والمالية في حال مخالفة ذلك، وللعاملة الحق الكامل في مغادرة الموقع فوراً دون إتمام العمل مع احتفاظها بالأجر كاملاً في حال تبين عدم صحة البيانات.';
    }

    /**
     * @return array<string, mixed>
     */
    public function policyPayload(): array
    {
        return [
            'requiredWhen' => [
                'field' => 'genderPreference',
                'value' => 'female',
            ],
            'title' => $this->title(),
            'question' => $this->question(),
            'options' => [
                [
                    'value' => self::BENEFICIARY_FEMALE_PRESENT,
                    'label' => 'سيدة (صاحبة المنزل / الزوجة / الأم)',
                    'allowed' => true,
                ],
                [
                    'value' => self::BENEFICIARY_MALE_ALONE,
                    'label' => 'رجل (بمفرده)',
                    'allowed' => false,
                    'blockedMessage' => $this->blockedMessage(),
                ],
            ],
            'pledge' => [
                'version' => $this->version(),
                'title' => $this->pledgeTitle(),
                'body' => $this->pledgeText(),
                'acceptanceLabel' => 'أوافق على التعهد وأتحمل المسؤولية',
            ],
        ];
    }
}
