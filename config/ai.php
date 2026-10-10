<?php

return [
    'default' => null,
    'default_for_images' => null,
    'default_for_audio' => null,
    'default_for_transcription' => null,
    'default_for_embeddings' => null,
    'default_for_reranking' => null,
    'providers' => [],
    'caching' => ['embeddings' => ['cache' => false]],
    'conversations' => ['generate_title' => false],
];
