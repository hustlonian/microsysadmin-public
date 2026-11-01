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
            Scripts run in a private temporary workspace at <code>/work</code>. Files disappear after each run. Network, application files and privileged operations are unavailable. Browser previews run in a separate sandbox.
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
                            <div class="card-body p-0"><iframe class="sandbox-frame sandbox-php-output" id="sandboxPhpOutput" sandbox="allow-scripts" referrerpolicy="no-referrer" title="PHP output"></iframe></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="sandboxBashPane" role="tabpanel" aria-labelledby="sandbox-bash-tab" tabindex="0">
                <form class="js-sandbox-run" data-language="bash" data-skip-confirm="1">
                    <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                    <input type="hidden" name="confirm_real" value="1">
                    <div class="card"><div class="card-header"><h2 class="card-title">Isolated script runner</h2></div>
                    <div class="card-body">
                        <label for="sandboxLanguage">Language</label>
                        <select class="form-select mb-3" name="language" id="sandboxLanguage">
                            <option value="bash">Bash</option><option value="python">Python</option><option value="javascript">JavaScript (Node)</option>
                        </select>
                        <label for="sandboxBashCode">Script</label>
                        <textarea class="form-control sandbox-editor" id="sandboxBashCode" name="code" spellcheck="false"><?= e($examples['bash']) ?></textarea>
                        <label for="sandboxInput">Standard input</label><textarea class="form-control" name="input" id="sandboxInput" maxlength="32768"></textarea>
                        <label for="sandboxTimeout">Timeout (1–30 seconds)</label><input class="form-control" id="sandboxTimeout" type="number" name="timeout" min="1" max="30" value="10">
                        <button class="btn btn-primary mt-3" type="submit">Run script</button>
                    </div></div>
                </form>
                <div class="card"><div class="card-header"><h2 class="card-title">Execution result</h2></div><div class="card-body"><pre class="sys-output sandbox-output" id="sandboxBashOutput" aria-live="polite"></pre></div></div>
            </div>

            <div class="tab-pane fade" id="sandboxWebPane" role="tabpanel" aria-labelledby="sandbox-web-tab" tabindex="0">
                <div class="row">
                    <div class="col-xl-6">
                        <form class="js-sandbox-web" data-preview-url="<?= e($workspace['preview_url']) ?>" data-real-title="Save and preview real browser files?" data-real-warning="This overwrites the saved HTML, CSS, and JavaScript files, then loads them in an isolated browser preview." data-confirm-label="Save & Preview">
                            <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                            <input type="hidden" name="action" value="save-web">
                            <input type="hidden" name="confirm_real" value="0">
                            <div class="card">
                                <div class="card-header d-flex align-items-center">
                                    <h2 class="card-title mb-0">Browser files</h2>
                                    <div class="ms-auto btn-group btn-group-sm">
                                        <button class="btn btn-primary" type="submit"><i class="fas fa-floppy-disk"></i> Save & Preview</button>
                                        <button class="btn btn-outline-secondary js-sandbox-load-preview" type="button" data-preview-url="<?= e($workspace['preview_url']) ?>" data-real-title="Preview saved browser files?" data-real-warning="This loads the saved files in a sandboxed preview without application privileges." data-confirm-label="Preview"><i class="fas fa-eye"></i> Preview Saved</button>
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
                                <iframe class="sandbox-frame" id="sandboxFrame" sandbox="allow-scripts" referrerpolicy="no-referrer" title="Code preview"></iframe>
                            </div>
                        </div>
                        <pre class="sys-output compact mt-3" id="sandboxConsole"></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
