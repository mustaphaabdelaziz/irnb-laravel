<?php

namespace App\Support;

/**
 * Stock French/Arabic names for the built-in finance categories and for the
 * names the app creates on its own from legacy category slugs
 * (TransactionObserver, finance:backfill, imports).
 *
 * Lookup is by the category's base name, never by code, so a category the
 * club renamed keeps its own label. English is never supplied: the base name
 * already is the English label through the localized_name fallback.
 */
final class FinanceCategoryTranslations
{
    /** Lowercase base name => [fr, ar]. Singular names get singular translations so they stay distinct from the plural built-ins. */
    private const NAMES = [
        // Built-in chart of accounts (create_finance_tables seed).
        'subscriptions' => ['Abonnements', 'اشتراكات'],
        'membership fees' => ['Cotisations', 'رسوم العضوية'],
        'donations' => ['Dons', 'تبرعات'],
        'grants / subsidies' => ['Subventions', 'منح / إعانات'],
        'arrears / debts' => ['Arriérés / Dettes', 'متأخرات / ديون'],
        'events revenue' => ['Recettes des événements', 'إيرادات التظاهرات'],
        'other income' => ['Autres recettes', 'إيرادات أخرى'],
        'equipment' => ['Équipement', 'معدات'],
        'maintenance & repairs' => ['Maintenance et réparations', 'صيانة وإصلاحات'],
        'supplies' => ['Fournitures', 'لوازم'],
        'works / construction' => ['Travaux / Construction', 'أشغال / بناء'],
        'utilities' => ['Charges (eau, électricité)', 'فواتير الخدمات'],
        'salaries / wages' => ['Salaires', 'رواتب وأجور'],
        'events expenses' => ['Dépenses des événements', 'مصاريف التظاهرات'],
        'administrative' => ['Frais administratifs', 'مصاريف إدارية'],
        'other expense' => ['Autres dépenses', 'مصاريف أخرى'],

        // Names auto-created from legacy slugs (Str::title of subscription, donation, debt_payment, ...).
        'subscription' => ['Abonnement', 'اشتراك'],
        'donation' => ['Don', 'تبرع'],
        'debt payment' => ['Paiement de dette', 'تسديد دين'],
        'membership fee' => ['Cotisation', 'رسم العضوية'],
        'equipment purchase' => ["Achat d'équipement", 'شراء معدات'],
        'debts' => ['Dettes', 'ديون'],
        'works' => ['Travaux', 'أشغال'],
        'other' => ['Autre', 'أخرى'],
        'miscellaneous' => ['Divers', 'متفرقات'],
    ];

    /**
     * Stock translations for a base name, or null when the name is not a known one.
     *
     * @return array{name_fr: string, name_ar: string}|null
     */
    public static function for(string $name): ?array
    {
        $stock = self::NAMES[mb_strtolower(trim($name))] ?? null;

        return $stock ? ['name_fr' => $stock[0], 'name_ar' => $stock[1]] : null;
    }
}
