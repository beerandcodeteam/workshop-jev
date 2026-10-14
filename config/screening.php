<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Resume Screening
    |--------------------------------------------------------------------------
    |
    | The screening page splits the work between two kinds of models. A
    | conventional LLM reads the job description once and writes the
    | criteria, and later writes the review of each resume. Every resume
    | is scored by one call to the decisions API, answering all criteria
    | at once. The default is the key of the decision model selected when
    | the page loads, from the "decision" models of config/moderation.php.
    |
    */

    'default' => env('SCREENING_MODEL', 'jev'),

    'llm' => [
        'label' => 'GPT Luna',
        'provider' => env('SCREENING_LLM_PROVIDER', 'openai'),
        'model' => env('SCREENING_LLM_MODEL', 'gpt-6-luna'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Decision Rules
    |--------------------------------------------------------------------------
    |
    | The rules the page applies to the answers, in plain code, and lets the
    | presenter change live. A resume is invited to an interview when its
    | weighted score reaches "invite_above" (from 0 to 100) and it passes
    | every knockout criterion, which needs at least "knockout_below" points.
    | Criteria from "strength_above" points up are listed as strengths.
    |
    */

    'invite_above' => 70,

    'knockout_below' => 50,

    'strength_above' => 70,

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | The largest file accepted, in kilobytes, and how many characters of the
    | extracted text are sent to the models.
    |
    */

    'max_upload' => 5120,

    'max_characters' => 12000,

];
