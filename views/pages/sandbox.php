<?php
$sources = $sources ?? $examples;
$workspace = $workspace ?? (new \SysAdmin\SandboxService())->workspace();
$cwd = $workspace['working_directory'] ?? APP_ROOT;
?>
<template id="sandboxPhpExample"><?= e($examples['php']) ?></template>
<template id="sandboxHtmlExample"><?= e($examples['html']) ?></template>
<template id="sandboxCssExample"><?= e($examples['css']) ?></template>
<template id="sandboxJsExample"><?= e($examples['javascript']) ?></template>
<div class="content-header"><div class="container-fluid"><h1 class="m-0">Code Runner</h1></div></div>
<section class="content">
    <div class="container-fluid">
        <div class="alert alert-warning">
            Code and shell commands run against real files as the web server user. PHP executes from <code><?= e($cwd) ?></code>; Bash opens a live shell; browser code is saved before previewing.
        </div>

        <ul class="nav nav-pills mb-3 sandbox-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="sandbox-php-tab" data-bs-toggle="pill" data-bs-target="#sandboxPhpPane" type="button" role="tab" aria-controls="sandboxPhpPane" aria-selected="true"><i class="fas fa-code"></i> PHP</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="sandbox-bash-tab" data-bs-toggle="pill" data-bs-target="#sandboxBashPane" type="button" role="tab" aria-controls="sandboxBashPane" aria-selected="false"><i class="fas fa-terminal"></i> Bash</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="sandbox-web-tab" data-bs-toggle="pill" data-bs-target="#sandboxWebPane" type="button" role="tab" aria-controls="sandboxWebPane" aria-selected="false"><i class="fas fa-window-maximize"></i> HTML/CSS/JS</button>
            </li>
        </ul>

        <div class="tab-content">
            <div class="tab-pane fade show active" id="sandboxPhpPane" role="tabpanel" aria-labelledby="sandbox-php-tab" tabindex="0">
                <div class="row">
                    <div class="col-xl-7">
                        <form class="js-sandbox-run" data-language="php" data-skip-confirm="1">
                            <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                            <input type="hidden" name="language" value="php">
                            <input type="hidden" name="confirm_real" value="0">
                            <div class="card">
                                <div class="card-header d-flex align-items-center">
                                    <h2 class="card-title mb-0">PHP runner</h2>
                                    <div class="ms-auto btn-group btn-group-sm">
                                        <button class="btn btn-primary" type="submit"><i class="fas fa-play"></i> Save & Run</button>
                                        <button class="btn btn-outline-secondary js-sandbox-reset" type="button" data-target="#sandboxPhpCode" aria-label="Reset PHP example" title="Reset PHP example"><i class="fas fa-arrow-rotate-left"></i></button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <textarea class="form-control sandbox-editor" id="sandboxPhpCode" name="code" data-example-target="sandboxPhpExample" spellcheck="false"><?= e($sources['php']) ?></textarea>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="col-xl-5">
                        <div class="card">
                            <div class="card-header"><h2 class="card-title">Output</h2></div>
                            <div class="card-body p-0"><iframe class="sandbox-frame sandbox-php-output" id="sandboxPhpOutput" title="PHP output"></iframe></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="sandboxBashPane" role="tabpanel" aria-labelledby="sandbox-bash-tab" tabindex="0">
                <div class="row">
                    <div class="col-12">
                        <div class="card sandbox-terminal-card" id="sandboxTerminalCard">
                            <div class="card-header d-flex align-items-center">
                                <div>
                                    <h2 class="card-title mb-1">Bash terminal</h2>
                                    <div class="sandbox-current-path">
                                        <i class="fas fa-folder-open"></i>
                                        <span>Working path:</span>
                                        <code id="sandboxBashCwd"><?= e($workspace['bash_working_directory'] ?? $workspace['working_directory']) ?></code>
                                    </div>
                                </div>
                                <div class="ms-auto d-flex align-items-center gap-2">
                                    <span class="sandbox-terminal-state" id="sandboxTerminalStatus">Idle</span>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-secondary js-sandbox-terminal-start" type="button" aria-label="Start or restart shell" title="Start or restart shell"><i class="fas fa-rotate-right"></i></button>
                                        <button class="btn btn-outline-secondary js-sandbox-terminal-fullscreen" type="button" aria-label="Toggle fullscreen terminal" title="Toggle fullscreen terminal" aria-pressed="false"><i class="fas fa-expand"></i></button>
                                        <button class="btn btn-outline-secondary js-sandbox-terminal-clear" type="button" aria-label="Clear terminal" title="Clear terminal"><i class="fas fa-eraser"></i></button>
                                        <button class="btn btn-outline-danger js-sandbox-terminal-stop" type="button" disabled aria-label="Stop terminal session" title="Stop terminal session"><i class="fas fa-stop"></i></button>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="sandbox-terminal" id="sandboxBashTerminal"></div>
                                <pre class="sys-output sandbox-output sandbox-terminal-fallback" id="sandboxBashOutput" hidden></pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="sandboxWebPane" role="tabpanel" aria-labelledby="sandbox-web-tab" tabindex="0">
                <div class="row">
                    <div class="col-xl-6">
                        <form class="js-sandbox-web" data-preview-url="<?= e($workspace['preview_url']) ?>" data-real-title="Save and preview real browser files?" data-real-warning="This overwrites the saved HTML, CSS, and JavaScript files, then loads them in the browser with normal page privileges." data-confirm-label="Save & Preview">
                            <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                            <input type="hidden" name="action" value="save-web">
                            <input type="hidden" name="confirm_real" value="0">
                            <div class="card">
                                <div class="card-header d-flex align-items-center">
                                    <h2 class="card-title mb-0">Browser files</h2>
                                    <div class="ms-auto btn-group btn-group-sm">
                                        <button class="btn btn-primary" type="submit"><i class="fas fa-floppy-disk"></i> Save & Preview</button>
                                        <button class="btn btn-outline-secondary js-sandbox-load-preview" type="button" data-preview-url="<?= e($workspace['preview_url']) ?>" data-real-title="Preview saved browser files?" data-real-warning="This loads the saved HTML, CSS, and JavaScript in the browser with normal page privileges." data-confirm-label="Preview"><i class="fas fa-eye"></i> Preview Saved</button>
                                        <button class="btn btn-outline-secondary js-sandbox-reset" type="button" data-target="#sandboxHtml,#sandboxCss,#sandboxJs" aria-label="Reset browser example" title="Reset browser example"><i class="fas fa-arrow-rotate-left"></i></button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <ul class="nav nav-tabs mb-3" role="tablist">
                                        <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#sandboxHtmlEditor" type="button" role="tab">HTML</button></li>
                                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#sandboxCssEditor" type="button" role="tab">CSS</button></li>
                                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#sandboxJsEditor" type="button" role="tab">JS</button></li>
                                    </ul>
                                    <div class="tab-content">
                                        <div class="tab-pane fade show active" id="sandboxHtmlEditor" role="tabpanel" tabindex="0">
                                            <textarea class="form-control sandbox-editor" id="sandboxHtml" name="html" data-example-target="sandboxHtmlExample" spellcheck="false"><?= e($sources['html']) ?></textarea>
                                        </div>
                                        <div class="tab-pane fade" id="sandboxCssEditor" role="tabpanel" tabindex="0">
                                            <textarea class="form-control sandbox-editor" id="sandboxCss" name="css" data-example-target="sandboxCssExample" spellcheck="false"><?= e($sources['css']) ?></textarea>
                                        </div>
                                        <div class="tab-pane fade" id="sandboxJsEditor" role="tabpanel" tabindex="0">
                                            <textarea class="form-control sandbox-editor" id="sandboxJs" name="javascript" data-example-target="sandboxJsExample" spellcheck="false"><?= e($sources['javascript']) ?></textarea>
                                        </div>
                                    </div>
                                    <div class="sandbox-paths mt-3">
                                        <span><i class="fas fa-file-code"></i> <code><?= e($workspace['web_index']) ?></code></span>
                                        <span><i class="fas fa-palette"></i> <code><?= e($workspace['web_css']) ?></code></span>
                                        <span><i class="fas fa-file-lines"></i> <code><?= e($workspace['web_js']) ?></code></span>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-header"><h2 class="card-title">Preview</h2></div>
                            <div class="card-body p-0">
                                <iframe class="sandbox-frame" id="sandboxFrame" title="Code preview"></iframe>
                            </div>
                        </div>
                        <pre class="sys-output compact mt-3" id="sandboxConsole"></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
