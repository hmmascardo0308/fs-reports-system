<?php
session_start();
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['username']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../welcome.php");
    exit;
}

if (isset($_GET['user_id'])) {
    $uid = (int)$_GET['user_id'];
    $conn->query("UPDATE fs_admin_messages SET is_read = 1 WHERE user_id = $uid AND is_from_admin = 0");
}

$users = $conn->query("
    SELECT 
        user_id, 
        MAX(username) AS username, 
        MAX(full_name) AS full_name,
        MAX(created_at) AS last_message,
        SUM(CASE WHEN is_from_admin = 0 AND is_read = 0 THEN 1 ELSE 0 END) AS unread
    FROM fs_admin_messages
    GROUP BY user_id
    ORDER BY last_message DESC
");

$username   = $_SESSION['username'] ?? "unknown";
$full_name  = $_SESSION['full_name'] ?? "unknown";
$user_type  = $_SESSION['user_type'] ?? "unknown";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Messages | FS Reports</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/all_users.css">
    <style>
        :root {
            --red: #dc2626;
            --red-dark: #b91c1c;
            --red-light: #fef2f2;
            --red-border: #fecaca;
            --text: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --bg: #f8fafc;
        }

        body {
            background: #f1f5f9;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text);
            text-decoration: none;
            font-weight: 500;
            font-size: 15px;
            transition: color 0.2s;
        }
        .back-link:hover {
            color: var(--red);
        }


        .content-wrapper {
            max-width: 1200px;
            margin: 24px auto;
            padding: 0 20px;
        }

        .page-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
        }
        .page-header h2 {
            margin: 0;
            font-size: 1.5rem;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .page-header h2 i {
            color: var(--red);
        }

        /* Layout */
        .messages-layout {
            display: grid;
            grid-template-columns: 340px 1fr;
            gap: 20px;
            height: calc(100vh - 170px);
            min-height: 520px;
        }

        /* User List */
        .user-list {
            background: #ffffff;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            border: 2px solid #7f1d1d;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .user-list-header {
            padding: 16px 18px;
            border-bottom: 1px solid var(--border);
            font-weight: 600;
            font-size: 14px;
            color: var(--text);
            background: var(--red-light);
            color: var(--red-dark);
        }
        .user-list-body {
            overflow-y: auto;
            flex: 1;
        }
        .user-item {
            padding: 14px 18px;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.15s;
        }
        .user-item:hover {
            background: #fef2f2;
        }
        .user-item.active {
            background: #fee2e2;
            border-left: 4px solid var(--red);
        }
        .user-item .name {
            font-weight: 600;
            color: var(--text);
            font-size: 14px;
        }
        .user-item .uname {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
        }
        .unread-badge {
            background: var(--red);
            color: white;
            font-size: 11px;
            font-weight: 700;
            min-width: 22px;
            height: 22px;
            padding: 0 6px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Chat View */
        .chat-view {
            background: white;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            border: 2px solid #7f1d1d;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .chat-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            font-weight: 600;
            font-size: 15px;
            color: var(--text);
            background: white;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .chat-header i {
            color: var(--red);
        }

        .messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background: #f8fafc;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .msg {
            max-width: 72%;
            display: flex;
            flex-direction: column;
        }
        .msg.user {
            align-self: flex-start;
        }
        .msg.admin {
            align-self: flex-end;
        }

        .bubble {
            padding: 11px 15px;
            border-radius: 14px;
            font-size: 14px;
            line-height: 1.5;
            word-wrap: break-word;
        }
        .msg.user .bubble {
            background: white;
            border: 1px solid var(--border);
            color: var(--text);
            border-bottom-left-radius: 4px;
        }
        .msg.admin .bubble {
            background: var(--red);
            color: white;
            border-bottom-right-radius: 4px;
        }
        .msg.bot .bubble {
            background: #fee2e2;
            color: #7f1d1d;
            border: 1px solid #fca5a5;
            border-bottom-left-radius: 4px;
        }

        .msg-meta {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 4px;
            padding: 0 4px;
        }
        .msg.admin .msg-meta {
            text-align: right;
        }

        /* Reply Box */
        .reply-box {
            padding: 14px 16px;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 10px;
            background: white;
        }
        .reply-box input {
            flex: 1;
            padding: 11px 16px;
            border: 1px solid #d1d5db;
            border-radius: 24px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }
        .reply-box input:focus {
            border-color: var(--red);
        }
        .reply-box button {
            background: var(--red);
            color: white;
            border: none;
            width: 46px;
            height: 46px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }
        .reply-box button:hover {
            background: var(--red-dark);
        }

        .empty-state {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            gap: 12px;
        }
        .empty-state i {
            font-size: 42px;
            opacity: 0.4;
        }
        .empty-state p {
            margin: 0;
            font-size: 15px;
        }

        /* Scrollbar */
        .user-list-body::-webkit-scrollbar,
        .messages::-webkit-scrollbar {
            width: 6px;
        }
        .user-list-body::-webkit-scrollbar-thumb,
        .messages::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
    </style>
</head>
<body>
    <header class="top-bar">
        <a href="user_dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back
        </a>
        <div class="user-badge">
            <span><?php echo htmlspecialchars($full_name); ?></span>
            <div class="avatar"><?php echo strtoupper(substr($full_name, 0, 1)); ?></div>
        </div>
    </header>

    <div class="content-wrapper">
        <div class="page-header">
            <h2><i class="fas fa-comments"></i> Admin Messages</h2>
        </div>

        <div class="messages-layout">
            <!-- User List -->
            <div class="user-list">
                <div class="user-list-header">
                    <i class="fas fa-users"></i> Conversations
                </div>
                <div class="user-list-body">
                    <?php if ($users && $users->num_rows > 0): ?>
                        <?php while ($u = $users->fetch_assoc()): ?>
                            <div class="user-item <?= (isset($_GET['user_id']) && $_GET['user_id'] == $u['user_id']) ? 'active' : '' ?>"
                                 onclick="location.href='?user_id=<?= $u['user_id'] ?>'">
                                <div>
                                    <div class="name"><?= htmlspecialchars($u['full_name']) ?></div>
                                    <div class="uname">@<?= htmlspecialchars($u['username']) ?></div>
                                </div>
                                <?php if ($u['unread'] > 0): ?>
                                    <span class="unread-badge"><?= $u['unread'] ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="padding: 40px 20px; text-align: center; color: #94a3b8; font-size: 14px;">
                            No conversations yet
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Chat Area -->
            <div class="chat-view">
                <?php if (isset($_GET['user_id'])): 
                    $uid = (int)$_GET['user_id'];
                    $msgs = $conn->query("SELECT * FROM fs_admin_messages WHERE user_id = $uid ORDER BY created_at ASC");
                    
                    // Get user info for header
                    $user_info = $conn->query("SELECT MAX(full_name) as full_name, MAX(username) as username FROM fs_admin_messages WHERE user_id = $uid")->fetch_assoc();
                ?>
                    <div class="chat-header">
                        <i class="fas fa-user-circle"></i>
                        <?= htmlspecialchars($user_info['full_name'] ?? 'User') ?>
                        <span style="font-weight:400;color:#94a3b8;font-size:13px;">
                            (@<?= htmlspecialchars($user_info['username'] ?? '') ?>)
                        </span>
                    </div>

                    <div class="messages" id="adminMessages">
                        <?php while ($m = $msgs->fetch_assoc()): 
                            $isBot = ($m['username'] === 'SYSTEM');
                            $isAdmin = ($m['is_from_admin'] == 1);
                            $msgClass = $isAdmin ? ($isBot ? 'bot' : 'admin') : 'user';
                            $label = $isBot ? 'FS Bot' : ($isAdmin ? 'You' : htmlspecialchars($m['full_name']));
                        ?>
                            <div class="msg <?= $msgClass ?>">
                                <div class="bubble"><?= nl2br(htmlspecialchars($m['message'])) ?></div>
                                <div class="msg-meta">
                                    <?= $label ?> • <?= date('M j, g:i A', strtotime($m['created_at'])) ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>

                    <form class="reply-box" method="post" action="ajax/admin_reply.php">
                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                        <input type="text" name="message" placeholder="Type your reply..." required autocomplete="off">
                        <button type="submit" title="Send">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-comments"></i>
                        <p>Select a conversation to start messaging</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Auto-scroll to bottom of messages
        const messagesContainer = document.getElementById('adminMessages');
        if (messagesContainer) {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
    </script>
</body>
</html>