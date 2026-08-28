<?php
return [
    'scanner'=>env('TASK_MEDIA_SCANNER','/usr/bin/clamdscan'),
    // Conservative initial cap; no automatic deletion or retention purge.
    'tenant_bytes'=>(int)env('TASK_MEDIA_TENANT_BYTES',1073741824),
];
