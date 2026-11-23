<?php
declare(strict_types=1);

namespace App;

/**
 * The update log shown to users under "Nyheder".
 * When you release an update: add a new entry at the TOP (newest first) with a higher version.
 * Every user then gets a one-time notice and a "Ny" badge until they open the page.
 */
final class Changelog
{
    public const ENTRIES = [
        [
            'version' => '1.9.0',
            'date' => '2026-11-13',
            'title' => 'Hurtigere AI',
            'changes' => [
                ['type' => 'improved', 'text' => 'De hurtigste AI-modeller bruges først, og en model, der ikke svarer inden for få sekunder, bliver droppet til fordel for den næste.'],
                ['type' => 'improved', 'text' => 'Opgavefeedback viser live, hvad AI’en arbejder på – og er blevet meget hurtigere.'],
                ['type' => 'new', 'text' => 'Spørgsmål, der er stillet før om det samme materiale, bliver besvaret med det samme.'],
            ],
        ],
        [
            'version' => '1.8.0',
            'date' => '2026-11-13',
            'title' => 'Animationer og beskyttelse af AI’en',
            'changes' => [
                ['type' => 'new', 'text' => 'Animationer: sider glider ind, tal tæller op, knapper og kort reagerer, og pop-ups åbner blødt.'],
                ['type' => 'new', 'text' => 'Forsøg på at manipulere AI’en (prompt injection) bliver stoppet. Svage tegn giver en advarsel – klare forsøg eller en ny advarsel blokerer kontoen og enheden, med årsagen vist.'],
            ],
        ],
        [
            'version' => '1.7.0',
            'date' => '2026-11-13',
            'title' => 'Bedre sikkerhed',
            'changes' => [
                ['type' => 'new', 'text' => 'Administratoren kan give en bruger en ny adgangskode. Brugeren bliver logget ud på alle enheder.'],
                ['type' => 'improved', 'text' => 'Skifter du selv adgangskode, bliver du logget ud på dine andre enheder.'],
                ['type' => 'improved', 'text' => 'Beskyttelse mod for mange forespørgsler og mod gætteri af adgangskoder.'],
            ],
        ],
        [
            'version' => '1.6.0',
            'date' => '2026-11-13',
            'title' => 'Opgavefeedback',
            'changes' => [
                ['type' => 'new', 'text' => 'Opgavefeedback: indsæt eller upload din opgave og din lærers kriterier, og få point pr. kriterie, en forventet karakter, konkrete forbedringer og rettelser af sprogfejl.'],
                ['type' => 'new', 'text' => 'Vælg et fag, så tjekkes fakta i din opgave mod dit eget materiale.'],
                ['type' => 'new', 'text' => '“Ret og tjek igen”: se hvor meget du er blevet bedre siden sidst – pr. kriterie.'],
                ['type' => 'new', 'text' => 'Skriv opgavens krav (f.eks. “Min. 800 ord” eller “Inddrag to kilder”), og se præcis hvad du mangler. Længdekrav tælles automatisk.'],
                ['type' => 'improved', 'text' => 'Karakteren vises med sin betydning på 7-trinsskalaen – fra -3 (ikke bestået) til 12 (fremragende).'],
            ],
        ],
        [
            'version' => '1.5.0',
            'date' => '2026-11-13',
            'title' => 'Reservenøgler til administratorer',
            'changes' => [
                ['type' => 'improved', 'text' => 'Alle bruger den første AI-nøgle. Når den er brugt op, kan kun administratorer fortsætte på de ekstra nøgler (kan slås fra under Indstillinger → AI-udbyder).'],
            ],
        ],
        [
            'version' => '1.4.0',
            'date' => '2026-11-11',
            'title' => 'Studieplan, deling og AI-måler',
            'changes' => [
                ['type' => 'new', 'text' => 'Studieplan: vælg din prøvedato, og få en plan for hver dag – læs, lær, quiz, flashcards og prøveeksamen. Opgaverne krydses af automatisk.'],
                ['type' => 'new', 'text' => '“I dag i din studieplan” på oversigten.'],
                ['type' => 'new', 'text' => 'Del et fag med et link, så andre kan kopiere det til deres egen konto – uden at bruge AI.'],
                ['type' => 'new', 'text' => 'AI-måler i menuen, der viser hvor mange af dagens AI-forespørgsler, der er tilbage.'],
                ['type' => 'new', 'text' => 'Administration: oversigt over brugere og forbrug, låsning af konti og en daglig AI-grænse pr. bruger.'],
            ],
        ],
        [
            'version' => '1.3.0',
            'date' => '2026-11-11',
            'title' => 'Hurtigere AI og nyt navn',
            'changes' => [
                ['type' => 'new', 'text' => 'Siden hedder nu Studiepilot.'],
                ['type' => 'improved', 'text' => 'Quizzer starter næsten med det samme: spørgsmålene bliver lavet på forhånd i baggrunden, mens du læser.'],
                ['type' => 'improved', 'text' => 'AI-læreren skriver svaret live, så du kan begynde at læse med det samme.'],
                ['type' => 'improved', 'text' => 'Den hurtigste AI-model bliver automatisk prøvet først.'],
                ['type' => 'new', 'text' => 'Studiepilot præsenterer sig selv og hjælper kun med skole og læring.'],
                ['type' => 'improved', 'text' => 'Den første API-nøgle bruges først. Er den begrænset, bruges den næste – og er alle begrænset, venter siden og prøver automatisk igen.'],
                ['type' => 'fixed', 'text' => 'Tydelig besked, når dagens gratis AI-grænse er brugt op, og baggrundsopgaver fortsætter selv, når den nulstilles.'],
                ['type' => 'new', 'text' => 'Denne opdateringslog under “Nyheder”.'],
            ],
        ],
        [
            'version' => '1.2.0',
            'date' => '2026-11-11',
            'title' => 'Læs først, øv bagefter',
            'changes' => [
                ['type' => 'new', 'text' => 'Flashcards, quizzer, prøveeksamen og hukommelsestræning låses op, når du har læst hele studieguiden.'],
                ['type' => 'improved', 'text' => 'Siden reagerer hurtigt, også mens AI’en arbejder i baggrunden.'],
                ['type' => 'fixed', 'text' => 'Rettet fejl, hvor et slettet fag blev ved med at stå som “Behandler”.'],
            ],
        ],
        [
            'version' => '1.1.0',
            'date' => '2026-11-11',
            'title' => 'Hele siden på dansk',
            'changes' => [
                ['type' => 'new', 'text' => 'Hele siden er nu på dansk, og AI’en skriver på dansk som standard.'],
                ['type' => 'new', 'text' => 'Vælg AI-sprog for hvert fag – f.eks. engelsk til engelskfaget – og skift sprog direkte hos AI-læreren.'],
                ['type' => 'new', 'text' => 'Opgavehjælp: forklaring af opgaven, feedback på din tekst og tjek af grammatik og stavning.'],
                ['type' => 'improved', 'text' => 'Kun administratoren kan se og ændre AI-nøgler og modeller.'],
            ],
        ],
        [
            'version' => '1.0.0',
            'date' => '2026-11-11',
            'title' => 'Første version',
            'changes' => [
                ['type' => 'new', 'text' => 'Upload PDF, Word, PowerPoint, Excel, ZIP, billeder, YouTube-videoer, websider og tekst.'],
                ['type' => 'new', 'text' => 'Automatisk analyse med emner, definitioner, vigtige fakta, datoer og en studieguide med kildehenvisninger.'],
                ['type' => 'new', 'text' => 'AI-quiz, flashcards med spaced repetition, prøveeksamen, AI-lærer, trin-for-trin-læring og hukommelsestræning.'],
            ],
        ],
    ];

    public static function version(): string
    {
        return self::ENTRIES[0]['version'];
    }

    /** API: the log plus whether the user has seen the latest version. */
    public static function list(): array
    {
        $u = Auth::require();
        $seen = (string) (((array) $u['prefs'])['seen_version'] ?? '');
        return ['version' => self::version(), 'seen_version' => $seen, 'entries' => self::ENTRIES];
    }

    /** API: marks the current version as seen by this user. */
    public static function seen(): array
    {
        $u = Auth::require();
        $prefs = (array) $u['prefs'];
        $prefs['seen_version'] = self::version();
        DB::update('users', ['prefs' => json_encode($prefs)], 'id = ?', [$u['id']]);
        return ['ok' => true, 'version' => self::version()];
    }
}
