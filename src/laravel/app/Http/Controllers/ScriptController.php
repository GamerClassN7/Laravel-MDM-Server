<?php

namespace App\Http\Controllers;

use App\Models\Script;
use SteelAnts\LaravelBoilerplate\Traits\CRUD;

/** Remediation scripts: the boilerplate CRUD page (script.data-table, script.form in a modal). */
class ScriptController extends BaseController
{
    use CRUD;

    public string $model = Script::class;

    public array $model_component = ['size' => 'xl'];
}
