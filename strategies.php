<?php
// strategies.php

// Load DB and common functions first
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

// Start protected layout (auth + header + sidebar)
app_start_protected();

$userId  = get_current_user_id();
$message = '';

// ---------- Handle POST actions ----------

// ---------- Handle POST actions ----------

// 0) Delete strategy
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $deleteId = (int)($_POST['delete_id'] ?? 0);

    if ($deleteId > 0) {
        $stmt = $mysqli->prepare(
            'DELETE FROM strategy_templates
             WHERE id = ? AND user_id = ?'
        );
        if ($stmt) {
            $stmt->bind_param('ii', $deleteId, $userId);
            if ($stmt->execute()) {
                $message = 'Strategy template deleted.';
            } else {
                $message = 'Failed to delete strategy template.';
            }
            $stmt->close();
        }
    }
}

// 1) Toggle enable/disable
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    $toggleId   = (int)($_POST['toggle_id'] ?? 0);
    $newEnabled = isset($_POST['new_status']) ? (int)$_POST['new_status'] : 0;

    $stmt = $mysqli->prepare(
        'UPDATE strategy_templates
         SET enabled = ?
         WHERE id = ? AND user_id = ?'
    );
    if ($stmt) {
        $stmt->bind_param('iii', $newEnabled, $toggleId, $userId);
        if ($stmt->execute()) {
            $message = $newEnabled ? 'Strategy enabled.' : 'Strategy disabled.';
        } else {
            $message = 'Failed to update strategy status.';
        }
        $stmt->close();
    }
}

// 2) Add / update strategy template
// 2) Add / update strategy template
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['template_name'])) {
    $name = trim($_POST['template_name'] ?? '');
    $desc = trim($_POST['template_desc'] ?? '');
    $id   = isset($_POST['template_id']) ? (int)$_POST['template_id'] : 0;

    if ($name !== '') {
        if ($id > 0) {
            // --- UPDATE EXISTING TEMPLATE ---
            // 1) Get old name so we can update trades using that strategy
            $oldName = null;
            $stmt = $mysqli->prepare(
                'SELECT name FROM strategy_templates WHERE id = ? AND user_id = ? LIMIT 1'
            );
            if ($stmt) {
                $stmt->bind_param('ii', $id, $userId);
                $stmt->execute();
                $stmt->bind_result($oldNameVal);
                if ($stmt->fetch()) {
                    $oldName = $oldNameVal;
                }
                $stmt->close();
            }

            // 2) Update the strategy template
            $stmt = $mysqli->prepare(
                'UPDATE strategy_templates
                 SET name = ?, description = ?
                 WHERE id = ? AND user_id = ?'
            );
            if ($stmt) {
                $stmt->bind_param('ssii', $name, $desc, $id, $userId);
                if ($stmt->execute()) {
                    $message = 'Strategy template updated.';

                    // 3) If name changed, update trades.setup_type
                    if ($oldName !== null && $oldName !== $name) {
                        $stmt2 = $mysqli->prepare(
                            'UPDATE trades
                             SET setup_type = ?
                             WHERE user_id = ? AND setup_type = ?'
                        );
                        if ($stmt2) {
                            // setup_type (new name), user_id, setup_type (old name)
                            $stmt2->bind_param('sis', $name, $userId, $oldName);
                            $stmt2->execute();
                            $stmt2->close();
                        }
                    }
                } else {
                    $message = 'Error updating template.';
                }
                $stmt->close();
            }
        } else {
            // --- INSERT NEW TEMPLATE (ENABLED BY DEFAULT) ---
            $stmt = $mysqli->prepare(
                'INSERT INTO strategy_templates (user_id, name, description, enabled)
                VALUES (?, ?, ?, 1)'
            );
            if ($stmt) {
                // 3 placeholders => 3 types
                $stmt->bind_param('iss', $userId, $name, $desc);
                if ($stmt->execute()) {
                    $message = 'Strategy template saved.';
                } else {
                    $message = 'Error saving template.';
                }
                $stmt->close();
            }
        }
    }
}

// 3) Playbook save
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['playbook_content'])) {
    $content = trim($_POST['playbook_content'] ?? '');
    $stmt = $mysqli->prepare(
        'REPLACE INTO playbooks (user_id, content) VALUES (?, ?)'
    );
    if ($stmt) {
        $stmt->bind_param('is', $userId, $content);
        if ($stmt->execute()) {
            $message = 'Playbook updated.';
        } else {
            $message = 'Error updating playbook.';
        }
        $stmt->close();
    }
}

// ---------- If editing a template, fetch that row ----------

$editTemplate = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $mysqli->prepare(
        'SELECT id, name, description
         FROM strategy_templates
         WHERE id = ? AND user_id = ? LIMIT 1'
    );
    if ($stmt) {
        $stmt->bind_param('ii', $editId, $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $editTemplate = $row;
        }
        $stmt->close();
    }
}

// ---------- Fetch templates WITH stats ----------
// Profit, loss, and trade count from trades.setup_type = strategy name

$sql = "
    SELECT
        st.id,
        st.name,
        st.description,
        st.created_at,
        COALESCE(st.enabled, 1) AS enabled,
        COALESCE(SUM(t.profit), 0) AS total_profit,
        COALESCE(SUM(t.loss), 0)   AS total_loss,
        COUNT(t.id)                AS trade_count
    FROM strategy_templates st
    LEFT JOIN trades t
        ON t.user_id = st.user_id
       AND t.setup_type = st.name
    WHERE st.user_id = ?
    GROUP BY st.id, st.name, st.description, st.created_at, st.enabled
    ORDER BY st.created_at DESC
";

$templates = [];
if ($stmt = $mysqli->prepare($sql)) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $templates[] = $row;
    }
    $stmt->close();
}

// ---------- Fetch playbook (single row per user) ----------

$playbookContent = '';
$pbStmt = $mysqli->prepare(
    'SELECT content FROM playbooks WHERE user_id = ? LIMIT 1'
);
if ($pbStmt) {
    $pbStmt->bind_param('i', $userId);
    $pbStmt->execute();
    $pbStmt->bind_result($playbookContent);
    $pbStmt->fetch();
    $pbStmt->close();
}
?>

<div class="content">
    <?php require_once __DIR__ . '/inc/topbar.php'; ?>
    <main class="main">
        <div class="card">
            <h2 class="card-title">Strategy Templates</h2>

            <?php if ($message): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        document.body.setAttribute('data-toast', <?php echo json_encode($message); ?>);
                    });
                </script>
            <?php endif; ?>

            <!-- Add / Edit Strategy Form -->
            <form method="post">
                <?php if ($editTemplate): ?>
                    <input type="hidden" name="template_id"
                           value="<?php echo (int)$editTemplate['id']; ?>">
                    <p class="text-muted mt-1">
                        Editing strategy:
                        <strong><?php echo htmlspecialchars($editTemplate['name']); ?></strong>
                        &nbsp;·&nbsp;
                        <a href="strategies.php" style="color:#60a5fa;">Cancel edit</a>
                    </p>
                <?php endif; ?>

                <div class="form-grid" style="margin-top:0.75rem;">
                    <div class="form-group">
                        <label for="template_name">Name</label>
                        <input
                            type="text"
                            id="template_name"
                            name="template_name"
                            required
                            value="<?php echo $editTemplate ? htmlspecialchars($editTemplate['name']) : ''; ?>"
                        >
                    </div>
                    <div class="form-group" style="grid-column:1/-1;">
                        <label for="template_desc">Description / Rules</label>

                        <!-- Toolbar -->
                        <div class="editor-toolbar">
                            <button type="button" class="editor-btn" data-editor-btn data-cmd="bold">
                                <strong>B</strong>
                            </button>
                            <button type="button" class="editor-btn" data-editor-btn data-cmd="underline">
                                <u>U</u>
                            </button>
                            <button type="button" class="editor-btn" data-editor-btn data-cmd="insertUnorderedList">
                                • Bullet list
                            </button>
                        </div>

                        <!-- Contenteditable area -->
                        <div id="template_desc_editor"
                            class="editor-area"
                            contenteditable="true"></div>

                        <!-- Hidden textarea that actually gets submitted -->
                        <textarea id="template_desc"
                                name="template_desc"
                                style="display:none;"><?php
                            echo $editTemplate ? htmlspecialchars($editTemplate['description']) : '';
                        ?></textarea>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn" type="submit">
                        <?php echo $editTemplate ? 'Update Template' : 'Save Template'; ?>
                    </button>
                </div>
            </form>

            <!-- Saved Templates with toggle view + stats -->
            <div class="mt-2">
                <h3 class="card-title" style="margin-top:1rem;">Saved Templates</h3>
                <?php if (empty($templates)): ?>
                    <p class="text-muted mt-1">No templates yet.</p>
                <?php else: ?>
                    <ul class="strategy-list mt-1">
                        <?php foreach ($templates as $t): ?>
                            <?php
                                $profit    = (float)$t['total_profit'];
                                $loss      = (float)$t['total_loss'];
                                $trades    = (int)$t['trade_count'];
                                $enabled   = (int)$t['enabled'] === 1;
                                $rowId     = (int)$t['id'];
                                $bodyId    = 'strategy-body-' . $rowId;
                            ?>
                            <li class="strategy-item">
                                <!-- Toggle header -->
                                <button type="button"
                                        class="strategy-toggle"
                                        data-target="<?php echo $bodyId; ?>">
                                    <div class="strategy-toggle-main">
                                        <span class="strategy-name">
                                            <?php echo htmlspecialchars($t['name']); ?>
                                        </span>
                                        <span class="badge <?php echo $enabled ? 'badge-enabled' : 'badge-disabled'; ?>">
                                            <?php echo $enabled ? 'Enabled' : 'Disabled'; ?>
                                        </span>
                                    </div>

                                    <div class="strategy-toggle-right">
                                        <div class="strategy-toggle-stats">
                                            P&amp;L:
                                            <span class="text-profit">
                                                ₹<?php echo number_format($profit, 2); ?>
                                            </span>
                                            &nbsp;/&nbsp;
                                            <span class="text-loss">
                                                -₹<?php echo number_format($loss, 2); ?>
                                            </span>
                                            &nbsp;·&nbsp;
                                            Trades: <?php echo $trades; ?>
                                        </div>
                                        <span class="strategy-toggle-arrow">▾</span>
                                    </div>
                                </button>

                                <!-- Collapsible body -->
                                <div class="strategy-body" id="<?php echo $bodyId; ?>">
                                    <div class="strategy-desc" style="margin-bottom:0.5rem;">
                                        <?php echo $t['description']; ?>
                                    </div>
                                    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                                        <a href="strategies.php?edit=<?php echo $rowId; ?>"
                                        class="btn btn-small">
                                            Edit
                                        </a>

                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="toggle_id"
                                                value="<?php echo $rowId; ?>">
                                            <input type="hidden" name="new_status"
                                                value="<?php echo $enabled ? 0 : 1; ?>">
                                            <button type="submit"
                                                    class="btn btn-small btn-secondary">
                                                <?php echo $enabled ? 'Disable' : 'Enable'; ?>
                                            </button>
                                        </form>

                                        <form method="post" style="display:inline;"
                                            onsubmit="return confirm('Delete this strategy template? This will NOT delete existing trades, only the template itself.');">
                                            <input type="hidden" name="delete_id"
                                                value="<?php echo $rowId; ?>">
                                            <button type="submit"
                                                    class="btn btn-small btn-danger">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <?php require_once __DIR__ . '/inc/footer.php'; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // --- Accordion open/close ---
    document.querySelectorAll('.strategy-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id   = this.getAttribute('data-target');
            const body = document.getElementById(id);
            if (!body) return;
            body.classList.toggle('open');
            this.classList.toggle('open');
        });
    });

    // --- Simple rich-text editor for Description / Rules ---
    const editor   = document.getElementById('template_desc_editor');
    const textarea = document.getElementById('template_desc');

    if (editor && textarea) {
        // 1) Initial content (from textarea -> editor)
        //    This can be plain text or already HTML from DB
        editor.innerHTML = textarea.value;

        // 2) Toolbar buttons (bold, underline, bullets)
        document.querySelectorAll('[data-editor-btn]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const cmd = this.getAttribute('data-cmd');
                if (!cmd) return;
                // `execCommand` is deprecated but still works fine in major browsers
                document.execCommand(cmd, false, null);
                editor.focus();
            });
        });

        // 3) On form submit, copy editor HTML back into textarea
        const form = textarea.closest('form');
        if (form) {
            form.addEventListener('submit', function () {
                textarea.value = editor.innerHTML;
            });
        }
    }
});
</script>