<?php
// Impede a listagem do conteúdo da pasta de uploads.
http_response_code(403);
exit('Acesso negado.');
