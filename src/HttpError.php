<?php
declare(strict_types=1);

namespace App;

/** An error whose message is safe and meant to be shown to the user. */
final class HttpError extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly ?string $code_ = null)
    {
        parent::__construct($message, $status);
    }

    public static function notFound(string $what = 'Item'): self
    {
        $da = ['Subject' => 'Faget', 'Source' => 'Kilden', 'Flashcard' => 'Flashcardet', 'Section' => 'Afsnittet', 'Quiz' => 'Quizzen',
            'Question' => 'Spørgsmålet', 'Job' => 'Opgaven', 'Topic' => 'Emnet', 'Conversation' => 'Samtalen', 'Learn session' => 'Læringsrunden'];
        return new self(404, ($da[$what] ?? 'Elementet') . ' blev ikke fundet.');
    }

    public static function bad(string $msg): self
    {
        return new self(422, $msg);
    }
}
