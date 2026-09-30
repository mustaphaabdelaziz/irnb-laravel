<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AttendanceFollowupTranslationsTest extends TestCase
{
    public const KEYS = [
        // Players at risk
        'att.risk.title', 'att.risk.rule_score', 'att.risk.rule_streak', 'att.risk.rule_off', 'att.risk.none',
        'att.risk.col.unexcused', 'att.risk.col.current_streak', 'att.risk.col.longest_streak', 'att.risk.col.last_session',
        'att.risk.col.reasons', 'att.risk.flag.low_score', 'att.risk.flag.streak', 'att.risk.open_profile',
        'att.risk.dash_title', 'att.risk.see_all', 'att.risk.banner', 'att.risk.banner_score', 'att.risk.banner_streak',
        // Parent letter
        'att.letter.print', 'att.letter.default_subject', 'att.letter.default_body', 'att.letter.to', 'att.letter.guardian_of',
        'att.letter.subject_label', 'att.letter.date_label', 'att.letter.details', 'att.letter.no_details', 'att.letter.totals',
        'att.letter.coach', 'att.letter.president', 'att.letter.settings_title', 'att.letter.settings_help',
        'att.letter.subject', 'att.letter.body', 'att.letter.in_ar', 'att.letter.in_fr', 'att.letter.in_en',
        'att.letter.ph.player', 'att.letter.ph.category', 'att.letter.ph.period', 'att.letter.ph.absences',
        'att.letter.ph.lates', 'att.letter.ph.club',
        // Ranking and certificates
        'att.ranking.title', 'att.ranking.type.month', 'att.ranking.type.season', 'att.ranking.help', 'att.ranking.col.rank',
        'att.ranking.col.present', 'att.ranking.unranked', 'att.ranking.none', 'att.ranking.print_top3',
        'att.ranking.certificate', 'att.ranking.season_label',
        'att.cert.title', 'att.cert.awarded_to', 'att.cert.line', 'att.cert.rank_1', 'att.cert.rank_2', 'att.cert.rank_3',
        'att.cert.score', 'att.cert.date',
        // Injuries
        'att.injury.title', 'att.injury.help', 'att.injury.current', 'att.injury.none_current', 'att.injury.in_period',
        'att.injury.none', 'att.injury.col.start', 'att.injury.col.end', 'att.injury.col.sessions', 'att.injury.col.body_part',
        'att.injury.col.description', 'att.injury.col.returned_on', 'att.injury.col.state', 'att.injury.open',
        'att.injury.closed', 'att.injury.add_details', 'att.injury.edit_details', 'att.injury.modal_title',
        'att.injury.unmatched', 'att.injury.unmatched_help', 'att.injury.error.no_spell', 'att.injury.save_error',
    ];

    #[Test]
    public function every_follow_up_label_exists_in_the_three_catalogs(): void
    {
        foreach (['ar', 'fr', 'en'] as $locale) {
            $catalog = json_decode((string) file_get_contents(resource_path("js/i18n/{$locale}.json")), true);
            foreach (self::KEYS as $key) {
                $this->assertNotEmpty($catalog[$key] ?? null, "{$locale}: {$key} is missing");
                $this->assertDoesNotMatchRegularExpression('/[|@]/', $catalog[$key], "{$locale}: {$key} uses vue-i18n syntax");
            }
        }
    }

    #[Test]
    public function the_default_letter_uses_every_placeholder(): void
    {
        foreach (['ar', 'fr', 'en'] as $locale) {
            $catalog = json_decode((string) file_get_contents(resource_path("js/i18n/{$locale}.json")), true);
            $this->assertStringContainsString('{player}', $catalog['att.letter.default_subject'], $locale);
            foreach (['{player}', '{category}', '{period}', '{absences}', '{lates}', '{club}'] as $placeholder) {
                $this->assertStringContainsString($placeholder, $catalog['att.letter.default_body'], "{$locale}: {$placeholder}");
            }
        }
    }
}
