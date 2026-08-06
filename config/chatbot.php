<?php

return [
    'rate_limit' => max(1, (int) env('CHATBOT_RATE_LIMIT', 20)),
];
