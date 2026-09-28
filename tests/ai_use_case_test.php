<?php
require_once dirname(__DIR__) . '/functions/ai.php';
$count = 0;
foreach (['General', 'Tickets', 'Documentation', 'Automation Investigation'] as $case) {
    $count++;
    if (aiModelUseCase($case) !== $case) { throw new RuntimeException('Valid AI use case rejected'); }
}
foreach (['', 'general', 'Automation', 'Unknown'] as $case) {
    $count++;
    try { aiModelUseCase($case); } catch (InvalidArgumentException $error) { continue; }
    throw new RuntimeException('Unapproved AI use case accepted');
}
echo "AI model use cases: $count assertions passed.\n";
