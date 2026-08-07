<?php // src/Runtime/RuntimeInterface.php
namespace Falco\Runtime;

use Falco\App;

interface RuntimeInterface
{
    public function serve(App $app): never;
}