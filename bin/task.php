<?php
declare(strict_types=1);

// Internal: one unit of analysis work, started in parallel by Pipeline::parallel().
//   php bin/task.php map   <jobId> <batchIndex>   → prints the partial analysis as JSON
//   php bin/task.php guide <subjectId> <topicId>
//   php bin/task.php cards <subjectId> <topicId>
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/src/bootstrap.php';
@set_time_limit(600);

[$_, $type, $a, $b] = array_pad($argv, 4, '0');
// Count AI requests for the subject's owner.
App\Usage::$userId = (int) ($type === 'map'
    ? App\DB::val('SELECT user_id FROM jobs WHERE id = ?', [(int) $a])
    : App\DB::val('SELECT user_id FROM subjects WHERE id = ?', [(int) $a]));
try {
    if ($type === 'map') {
        $job = App\DB::one('SELECT * FROM jobs WHERE id = ?', [(int) $a]);
        $payload = App\Support::decode($job['payload'] ?? null);
        App\Lang::forSubjectId((int) $job['subject_id']);
        $batch = (array) ($payload['batches'][(int) $b] ?? []);
        echo json_encode(['ok' => true, 'result' => App\Analyzer::mapBatch((int) $job['user_id'], $batch)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } elseif ($type === 'questions') {
        $subject = App\DB::one('SELECT * FROM subjects WHERE id = ?', [(int) $a]);
        if ($subject) {
            App\Lang::forSubject($subject);
            App\Quiz::prefillTopic($subject, (int) $b);
        }
        echo json_encode(['ok' => true]);
    } elseif ($type === 'guide' || $type === 'cards') {
        $subject = App\DB::one('SELECT * FROM subjects WHERE id = ?', [(int) $a]);
        $topic = App\DB::one('SELECT * FROM topics WHERE id = ? AND subject_id = ?', [(int) $b, (int) $a]);
        if ($subject && $topic) {
            App\Lang::forSubject($subject);
            App\Analyzer::topicMaterials($subject, $topic, $type);
        }
        echo json_encode(['ok' => true]);
    } else {
        throw new RuntimeException('Unknown task');
    }
} catch (Throwable $e) {
    App\Support::log("Task $type $a $b failed: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'status' => $e instanceof App\HttpError ? $e->status : 500, 'code' => $e instanceof App\HttpError ? $e->code_ : null]);
}
