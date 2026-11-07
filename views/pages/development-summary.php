<?php $narrative = \SysAdmin\DevelopmentNarrative::summary($report); ?>
<section id="development-summary" data-source-time="<?= e($report['source']['persisted_at'] ?? '') ?>" class="card mx-3 mt-3" aria-labelledby="development-summary-title">
    <div class="card-header">
        <h2 id="development-summary-title" class="h4 mb-1">Digital Empire — development progress</h2>
        <p class="mb-0">What has been done, what is being worked on, and what comes next.</p>
    </div>
    <div class="card-body">
    <?php if (empty($report['available'])): ?>
        <p role="status">The development report is unavailable. Current progress cannot be confirmed. Try refreshing shortly.</p>
    <?php else: ?>
        <p class="text-muted" role="status">Last reported: <time><?= e($report['source']['persisted_at']) ?></time>.
        <?= $narrative['active'] ? 'The reporting controller is updating. This is its latest recorded work.' : 'Updates are not confirmed current. The work below is the last recorded position.' ?></p>
        <article class="mb-4" aria-labelledby="development-story-title" id="development-story">
            <h3 class="h5" id="development-story-title">The story so far</h3>
            <?php foreach (\SysAdmin\DevelopmentNarrative::story($report) as $paragraph): ?>
                <p><?= e($paragraph) ?></p>
            <?php endforeach; ?>
            <p class="small text-muted">This account is written from the same recorded evidence, latest reported work, plan, and pending decisions shown below. Its last update is shown above.</p>
        </article>
        <div class="row">
            <article class="col-lg-4 mb-3">
                <h3 class="h5">Done</h3>
                <p class="small text-muted">Completed checkpoints with verified historical evidence. This does not mean the whole product is finished or released.</p>
                <?php if (!$narrative['done']): ?><p>No verified completed work is available in this report.</p><?php endif; ?>
                <ul class="ps-3">
                <?php foreach ($narrative['done'] as $entry): ?>
                    <li class="mb-2"><?= e(\SysAdmin\DevelopmentNarrative::describe($entry['task'])) ?>
                        <details><summary class="small">When and how it was checked</summary>
                            <p class="small"><?= e($entry['observed_at'] ?? 'Time not recorded') ?><br>Source: <code><?= e(substr($entry['commit'], 0, 12)) ?></code><br>
                            <?= e($entry['verification']) ?>. Tests: <?= e(json_encode($entry['tests'])) ?>.</p>
                        </details>
                    </li>
                <?php endforeach; ?>
                </ul>
            </article>
            <article class="col-lg-4 mb-3">
                <h3 class="h5" data-current-work-title><?= $narrative['active'] ? 'Doing now — latest reported work' : 'Last reported work' ?></h3>
                <p><?= e($narrative['current']) ?></p>
                <p>Reported contributor: <?= e($report['current_agent'] ?? 'Not recorded') ?>.</p>
                <p class="small">A reporting heartbeat confirms updates, not that an agent is executing a task right now.</p>
                <?php if (!empty($report['mission']['expected_deliverable'])): ?>
                    <details><summary>Expected result</summary><p><?= e($report['mission']['expected_deliverable']) ?></p></details>
                <?php endif; ?>
            </article>
            <article class="col-lg-4 mb-3">
                <h3 class="h5">Coming next</h3>
                <p><?= e($narrative['next']) ?></p>
                <p class="small text-muted">This is the controller’s planned next step, subject to its checks and permissions. No completion date has been promised.</p>
                <a href="<?= e(url('development-command-center') . '?project=' . rawurlencode($report['project']['id']) . '&view=roadmap') ?>">See the full plan</a>
            </article>
        </div>
        <h3 class="h5">Waiting for your decision</h3>
        <?php if (!$narrative['decisions']): ?><p>No pending owner decisions are recorded in this report.</p><?php else: ?>
        <ul><?php foreach ($narrative['decisions'] as $decision): ?><li><?= e($decision) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <p class="small text-muted">Overall release readiness has not been verified. Historical test results do not establish current production readiness.</p>
        <a class="btn btn-outline-primary" href="<?= e(url('development-command-center')) ?>">Development details, history and evidence</a>
    <?php endif; ?>
    </div>
</section>

<script>
if (!window.developmentFreshnessTimer) {
    window.developmentFreshnessTimer = setInterval(() => {
        const section = document.getElementById('development-summary');
        if (!section) return;
        const age = Date.now() - Date.parse(section.dataset.sourceTime);
        if (Number.isFinite(age) && age >= 0 && age <= 60000) return;
        const title = section.querySelector('[data-current-work-title]');
        if (title) title.textContent = 'Last reported work';
        const status = section.querySelector('[role="status"]');
        if (status && section.dataset.sourceTime) status.textContent = 'Updates are not confirmed current. Last reported: ' + section.dataset.sourceTime + '. The work below is the last recorded position.';
    }, 5000);
}
</script>
