<?php
declare(strict_types=1);

namespace App;

/**
 * Which language the AI writes in. Resolution order:
 * per-request override (e.g. tutor switch) → subject setting → user's default → "auto" (match the material).
 * The instruction is appended to every AI system prompt by AI::chat().
 */
final class Lang
{
    public const LANGUAGES = [
        'da' => 'Dansk',
        'en' => 'Engelsk (English)',
        'auto' => 'Samme som mit materiale',
        'de' => 'Tysk (Deutsch)',
        'fr' => 'Fransk (Français)',
        'es' => 'Spansk (Español)',
        'sv' => 'Svensk (Svenska)',
        'no' => 'Norsk',
        'nl' => 'Hollandsk (Nederlands)',
        'it' => 'Italiensk (Italiano)',
        'pt' => 'Portugisisk (Português)',
        'pl' => 'Polsk (Polski)',
        'fi' => 'Finsk (Suomi)',
    ];

    private const ENGLISH_NAMES = [
        'en' => 'English', 'da' => 'Danish', 'sv' => 'Swedish', 'no' => 'Norwegian', 'de' => 'German', 'fr' => 'French',
        'es' => 'Spanish', 'nl' => 'Dutch', 'it' => 'Italian', 'pt' => 'Portuguese', 'pl' => 'Polish', 'fi' => 'Finnish',
    ];

    private static string $code = 'auto';

    public static function valid(?string $code): bool
    {
        return $code !== null && isset(self::LANGUAGES[$code]);
    }

    public static function set(?string $code): void
    {
        self::$code = self::valid($code) ? $code : 'auto';
    }

    public static function code(): string
    {
        return self::$code;
    }

    /** Sets the language from a subject row (falls back to the owner's default). */
    public static function forSubject(?array $subject, ?string $override = null): void
    {
        if (self::valid($override) && $override !== 'default') {
            self::set($override);
            return;
        }
        // Subject language: empty = use the student's default; otherwise a code (incl. "auto").
        $code = (string) ($subject['language'] ?? '');
        if (!self::valid($code)) {
            $prefs = Support::decode((string) DB::val('SELECT prefs FROM users WHERE id = ?', [$subject['user_id'] ?? 0]), []);
            $default = (string) Env::get('DEFAULT_AI_LANGUAGE', 'da');
            $code = is_array($prefs) ? (string) ($prefs['ai_language'] ?? $default) : $default;
        }
        self::set($code);
    }

    public static function forSubjectId(int $subjectId, ?string $override = null): void
    {
        self::forSubject(DB::one('SELECT id, user_id, language FROM subjects WHERE id = ?', [$subjectId]), $override);
    }

    public static function name(): string
    {
        return self::ENGLISH_NAMES[self::$code] ?? 'the language of the source material';
    }

    /** Instruction appended to system prompts. */
    public static function instruction(): string
    {
        if (self::$code === 'auto') {
            return 'LANGUAGE: Write in the same language as the source material (if the student writes to you in another language, reply in the student\'s language).';
        }
        $name = self::ENGLISH_NAMES[self::$code];
        return "LANGUAGE: Write ALL output for the student in $name, even if the source material is in another language. "
            . "This includes every heading, label and fixed phrase that these instructions give you in English (e.g. section headings like 'Summary' or 'Key facts', or 'General knowledge (not from your sources)') — translate them too. "
            . "Translate terms and explanations accurately; when a key term comes from material in another language, you may give the original term in parentheses. "
            . "Keep JSON keys and citation ids like [C12] unchanged.";
    }
}
