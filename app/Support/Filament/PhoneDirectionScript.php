<?php

declare(strict_types=1);

namespace App\Support\Filament;

use Illuminate\Support\HtmlString;

final class PhoneDirectionScript
{
    public static function render(): HtmlString
    {
        return new HtmlString(<<<'HTML'
        <script>
            (() => {
                const phonePattern = /^\+?\d[\d\s().-]{6,}\d$/;
                let scheduled = false;

                const markLtr = (element) => {
                    element.setAttribute('dir', 'ltr');
                    element.style.unicodeBidi = 'isolate';
                    element.style.textAlign = 'left';
                };

                const normalize = (root = document) => {
                    root.querySelectorAll?.(
                        'input[type="tel"], input[name*="phone" i], input[id*="phone" i], a[href^="tel:"], .fi-phone-ltr'
                    ).forEach(markLtr);


                    root.querySelectorAll?.('span, div, p, td, dd').forEach((element) => {
                        if (element.children.length > 0) {
                            return;
                        }

                        const value = (element.textContent || '').trim();
                        if (!phonePattern.test(value) || (value.match(/\d/g) || []).length < 9) {
                            return;
                        }

                        markLtr(element);
                    });
                };

                const scheduleNormalize = () => {
                    if (scheduled) {
                        return;
                    }

                    scheduled = true;
                    requestAnimationFrame(() => {
                        scheduled = false;
                        normalize(document);
                    });
                };

                document.addEventListener('DOMContentLoaded', scheduleNormalize, { once: true });
                document.addEventListener('livewire:navigated', scheduleNormalize);
                new MutationObserver(scheduleNormalize).observe(document.documentElement, {
                    childList: true,
                    subtree: true,
                });
            })();
        </script>
        HTML);
    }
}
