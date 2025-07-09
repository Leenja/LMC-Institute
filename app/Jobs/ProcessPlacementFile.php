<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Str;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessPlacementFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $relativePath;

    public function __construct(string $relativePath)
    {
        if(empty($relativePath)){
            throw new \InvalidArgumentException("Relative path cannot be empty");
        }
        $this->relativePath = $relativePath;
    }

public function handle()
{
    $fullPath = storage_path('app/' . str_replace('/', DIRECTORY_SEPARATOR, $this->relativePath));

    if (!file_exists($fullPath)) {
        Log::error("File not found: $fullPath");
        return;
    }

    try {
        Log::info("Sending file to Python API: " . $fullPath);

        $client = new \GuzzleHttp\Client();
        $response = $client->post('http://127.0.0.1:8001/process-file/', [
            'multipart' => [
                [
                    'name'     => 'file',
                    'contents' => fopen($fullPath, 'r'),
                    'filename' => basename($fullPath),
                ],
            ],
        ]);

        $body = $response->getBody()->getContents();
        Log::info("Received response from Python API: " . $body);

        $data = json_decode($body, true);

        if (!isset($data['Questions']) || !is_array($data['Questions'])) {
            Log::error('Invalid data format: Questions key missing or not array');
            return;
        }

        foreach ($data['Questions'] as $q) {
            if (!isset($q['QuestionText'])) {
                Log::error('QuestionText key missing in question');
                continue;
            }

            Log::info('Saving question: ' . $q['QuestionText']);

            $section = $q['Section'] ?? null;

            if ($section) {
                $section = trim($section);
                if (Str::lower(str_replace(' ', '', $section)) === 'languageuse') {
                    $section = 'LanguageUse';
                } elseif (Str::lower($section) === 'reading') {
                    $section = 'Reading';
                } elseif (Str::lower($section) === 'listening') {
                    $section = 'Listening';
                } else {
                    Log::warning("Invalid or unknown section: " . $section . " for question: " . $q['QuestionText']);
                    $section = null;
                }
            } else {
                Log::warning("Missing section for question: " . $q['QuestionText']);
            }

            $questionId = DB::table('placement_test_questions')->insertGetId([
                'Section' => $section,
                'Context' => $q['Context'] ?? null,
                'QuestionText' => $q['QuestionText'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($q['Answers'] ?? [] as $ans) {
                Log::info('Saving answer: ' . $ans['AnswerText']);
                DB::table('placement_test_answers')->insert([
                    'QuestionId' => $questionId,
                    'AnswerText' => $ans['AnswerText'],
                    'isCorrect' => $ans['isCorrect'] ?? false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        unlink($fullPath);
        Log::info("File processed and deleted: " . $fullPath);
    } catch (\Exception $e) {
        Log::error('Error in ProcessPlacementFile job: ' . $e->getMessage());
    }
}

}
