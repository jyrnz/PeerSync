<?php
session_start();
include 'config.php';

// Fetch user data
$user_id = $_SESSION['user_id'];
$query = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch users who the logged-in user has messaged or received messages from
$sql = "SELECT DISTINCT u.id, u.username, u.profile_image 
  FROM users u
  JOIN direct_messages dm ON (u.id = dm.sender_id OR u.id = dm.receiver_id)
  WHERE dm.sender_id = ? OR dm.receiver_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $user_id, $user_id);
$stmt->execute();
$dm_users = $stmt->get_result();
$stmt->close();

// Fetch receiver_id from URL
$receiver_id = isset($_GET['receiver_id']) ? intval($_GET['receiver_id']) : 0;

// Fetch messages between the logged-in user and the receiver
$messages = [];
if ($receiver_id) {
  $msg_query = "SELECT dm.*, u.username, u.profile_image FROM direct_messages dm
    JOIN users u ON dm.sender_id = u.id
    WHERE (dm.sender_id = ? AND dm.receiver_id = ?) 
     OR (dm.sender_id = ? AND dm.receiver_id = ?) 
    ORDER BY dm.timestamp ASC";
  $msg_stmt = $conn->prepare($msg_query);
  $msg_stmt->bind_param("iiii", $user_id, $receiver_id, $receiver_id, $user_id);
  $msg_stmt->execute();
  $messages = $msg_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $msg_stmt->close();
}

// Handle deletion of direct messages
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_message_id'])) {
  $message_id = intval($_POST['delete_message_id']);
  $sql = "DELETE FROM direct_messages WHERE id = ?";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("i", $message_id);
  if ($stmt->execute()) {
    header("Location: indexBubble.php?receiver_id=" . $receiver_id . "&message=Message deleted successfully");
    exit();
  } else {
    header("Location: indexBubble.php?receiver_id=" . $receiver_id . "&message=Error deleting message: " . urlencode($stmt->error));
    exit();
  }
  $stmt->close();
}

// Handle editing of direct messages
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_message_id'])) {
  $edit_message_id = intval($_POST['edit_message_id']);
  $new_message = $_POST['new_message'];

  // Update the message in the database
  $update_query = "UPDATE direct_messages SET message = ? WHERE id = ? AND sender_id = ?";
  $update_stmt = $conn->prepare($update_query);
  $update_stmt->bind_param('sii', $new_message, $edit_message_id, $user_id);
  $update_stmt->execute();
  $update_stmt->close();

  // Redirect to the same page to refresh the list of messages
  header("Location: indexBubble.php?receiver_id=" . $receiver_id);
  exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Index Bubble</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="style.css">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  
  <style>
  .dropdown:hover .dropdown-menu { display: block; }
  .modal { display: none; position: fixed; z-index: 50; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0, 0, 0, 0.4); }
  .modal-content { background-color: #fefefe; margin: 15% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 500px; border-radius: 8px; }
  .sidebar { width: 80px; transition: width 0.3s; position: fixed; top: 0; left: 0; height: 100%; overflow: visible; }

  .navbar {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    z-index: 1000;
  }
  .dropdown-menu {
  z-index: 50; /* Ensure this value is higher than other elements */
  background: white;
  border: 1px solid #e5e7eb;
  border-radius: 14px;
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
  min-width: 120px;
}

.dropdown-menu a, 
.dropdown-menu button {
  display: block;
  padding: 8px 12px;
  width: 100%;
  text-align: left;
  font-size: 0.875rem;
  color: #374151;
  transition: all 0.2s;
}

.dropdown-menu a:hover, 
.dropdown-menu button:hover {
  background-color: #f3f4f6;
}

.dropdown-menu .text-red-600:hover {
  background-color: #fee2e2;
}

  .message-bubble {
    position: relative;
    max-width: 85%;
    padding: 10px 16px;
    margin: 1px 0;
    border-radius: 18px;
    font-size: 15px;
    line-height: 1.35;
    word-wrap: break-word;
    -webkit-font-smoothing: antialiased;
  }

  .message-sent {
    background: #007AFF;
    color: white;
    margin-left: auto;
    border-bottom-right-radius: 4px;
    margin-right: 8px;
  }

  .message-received {
    background: #E9E9EB;
    color: black;
    margin-right: auto;
    border-bottom-left-radius: 4px;
    margin-left: 8px;
  }

  .message-time {
    font-size: 10px;
    margin-top: 3px;
    color: #8E8E93;
    padding: 0 12px;
  }

  .message-container {
    margin: 2px 0;
    padding: 1px 0;
  }

  .message-container.consecutive {
    margin-top: -8px;
  }

  .message-container:first-child {
    margin-top: 8px;
  }

  .message-container:last-child {
    margin-bottom: 8px;
  }

  .message-options {
    opacity: 0;
    transition: opacity 0.2s ease;
    padding: 4px;
  }

  .message-container:hover .message-options {
    opacity: 1;
  }

  .profile-image {
    width: 28px;
    height: 28px;
    border-radius: 14px;
    margin-bottom: 4px;
  }

  .sender-name {
    font-size: 12px;
    color: #8E8E93;
    margin-bottom: 2px;
    margin-left: 12px;
  }

  .date-separator {
    margin: 16px 0;
    text-align: center;
  }

  .date-bubble {
    background: rgba(0, 0, 0, 0.1);
    padding: 4px 12px;
    border-radius: 16px;
    font-size: 12px;
    color: #8E8E93;
    display: inline-block;
  }

  .message-input-container {
    background-color: #ffffff;
    border-top: 1px solid #e5e7eb;
    padding: 1rem;
    position: sticky;
    bottom: 0;
    width: 100%;
    box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
  }

  .message-input-wrapper {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    max-width: 1200px;
    margin: 0 auto;
  }

  .message-input {
    flex-grow: 1;
    padding: 0.75rem 1rem;
    border: 1px solid #e5e7eb;
    border-radius: 9999px;
    font-size: 0.95rem;
    line-height: 1.5;
    outline: none;
    transition: all 0.2s ease;
    background-color: #f9fafb;
  }

  .message-input:focus {
    background-color: #ffffff;
    border-color: #60a5fa;
    box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.1);
  }

  .message-input::placeholder {
    color: #9ca3af;
  }

  .send-button {
    background-color: #2563eb;
    color: white;
    padding: 0.75rem 1.5rem;
    border-radius: 9999px;
    font-size: 0.95rem;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s ease;
    border: none;
    cursor: pointer;
  }

  .send-button:hover {
    background-color: #1d4ed8;
    transform: translateY(-1px);
  }

  .send-button:active {
    transform: translateY(0);
  }

  .send-button i {
    font-size: 0.9rem;
  }

  /* Adjust chat messages container to make room for input */
  #chat-messages {
    margin-bottom: 0;
    padding-bottom: 1rem;
  }
  </style>
</head>
<body class="bg-white h-screen flex flex-col">
    <!-- Navbar -->
    <nav class="navbar bg-secondary-100 text-white flex justify-between items-center" style="background-color: rgb(43 84 126 / var(--tw-bg-opacity)) /* #2b547e */;
}">
        <div class="flex items-center">
            <a href="indexTimeline.php"><img src="../public/ps.png" alt="Peerync Logo" class="h-16 w-16"></a>
            <span class="text-2xl font-bold">PeerSync</span>
        </div>
        <div class="flex items-center">
            <a href="exploreBubble.php" class="ml-4 hover:bg-blue-400 p-2 rounded">
                <i class="fas fa-globe fa-lg"></i>
            </a>
            <a href="indexBubble.php" class="ml-4 hover:bg-blue-400 p-2 rounded">
                <i class="fas fa-comments fa-lg"></i>
            </a>
            <a href="notebook.php" class="ml-4 hover:bg-blue-400 p-2 rounded">
                <i class="fas fa-book fa-lg"></i>
            </a>
            <div class="relative ml-4  p-4">
                <img src="<?php echo htmlspecialchars($user['profile_image']); ?>" alt="Profile Image" class="w-10 h-10 rounded-full cursor-pointer" id="profileImage">
                <div class="dropdown-menu absolute right-0 mt-1 w-48 bg-white border border-gray-300 rounded shadow-lg hidden">
                    <a href="profile.php" class="block px-4 py-2 text-gray-700 hover:bg-gray-100">Profile</a>
                    <a href="logout.php" class="block px-4 py-2 text-gray-700 hover:bg-gray-100">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Leftmost Sidebar -->
    <div id="sidebar" class="fixed top-0 left-0 h-full mt-10 text-white z-50 flex flex-col items-center sidebar transition-all duration-300 shadow-lg border-r border-gray-300" style="width: 64px; background-color: rgb(70 130 180 / 50%) /* #4682b4 */;">

    <ul id="bubble-list" class="space-y-4 mt-10">
            <!-- Bubble list will be populated by JavaScript -->
        </ul>
    </div>

  <!-- Main Content -->
  <div class="main-content flex-grow flex overflow-hidden mt-16">
  <!-- Sidebar with bubble details -->
  <div class="sidebarb w-64 bg-blue-50 text-sky-700 p-5 overflow-y-auto flex-shrink-0 ml-20 shadow-lg transition-transform transform" style="margin-left: 64px;">
  <div class="sidebarb">
  <h2 class="text-xl font-bold mb-4">Direct Messages</h2>
  <ul class="space-y-4">
      <?php while ($dm_user = $dm_users->fetch_assoc()): ?>
    <li class="flex items-center space-x-2 cursor-pointer" data-user-id="<?php echo $dm_user['id']; ?>">
        <img src="<?php echo htmlspecialchars($dm_user['profile_image']); ?>" alt="Profile Image" class="w-8 h-8 rounded-full">
        <span><?php echo htmlspecialchars($dm_user['username']); ?></span>
    </li>
      <?php endwhile; ?>
  </ul>
    </div>
  </div>
  <div class="main-content flex-grow flex flex-col h-full p-5 bg-white ">
    <?php if (!$receiver_id): ?>
      <div class="h-full flex items-center justify-center ">
        <div class="text-center">
          <i class="fas fa-comments text-gray-300 text-6xl mb-4 block mt-10"></i>
          <p class="text-xl text-gray-500 mt-10">Click any user to start a conversation</p>
        </div>
      </div>
    <?php else: ?>
      <!-- Your existing conversation code here -->
    <?php endif; ?>
  <!-- Content Container -->
  <div class="main-content flex-grow flex flex-col h-full p-5 bg-white">
    <?php if ($receiver_id): ?>
      <?php 
        // Fetch receiver information
        $receiver_query = "SELECT username, profile_image FROM users WHERE id = ?";
        $stmt = $conn->prepare($receiver_query);
        $stmt->bind_param("i", $receiver_id);
        $stmt->execute();
        $receiver = $stmt->get_result()->fetch_assoc();
        $stmt->close();
      ?>
      <!-- Receiver Header -->
      <div class="bg-white shadow-md rounded-lg p-4 mb-0 flex items-center justify-between border-b border-gray-200 hover:bg-gray-50 transition-colors duration-200">
        <div class="flex items-center">
          <img src="<?php echo htmlspecialchars($receiver['profile_image']); ?>" alt="Profile" class="w-10 h-10 rounded-full">
          <span class="ml-3 font-semibold"><?php echo htmlspecialchars($receiver['username']); ?></span>
        </div>
        <div class="relative">
          <button class="text-gray-500 hover:text-gray-700 focus:outline-none p-2" onclick="toggleDropdown(this)">
            <i class="fas fa-ellipsis-v"></i>
          </button>
          <div class="dropdown-menu absolute right-0 mt-2 w-48 bg-white border border-gray-300 rounded-2xl shadow-lg hidden z-10">
            <a href="#" class="block px-4 py-2 text-gray-700 hover:bg-gray-100 first:rounded-t-2xl">View Profile</a>
            <a href="#" class="block px-4 py-2 text-gray-700 hover:bg-gray-100">Clear Chat</a>
            <a href="#" class="block px-4 py-2 text-red-600 hover:bg-red-50 last:rounded-b-2xl">Block User</a>
          </div>
        </div>
      </div>
    <?php endif; ?>
    <div id="chat-messages" class="flex-grow p-4 space-y-4 overflow-y-auto">
        <?php foreach ($messages as $message): 
            $isOwnMessage = $message['sender_id'] == $user_id;
            $timestamp = date('M j, Y g:i A', strtotime($message['timestamp']));
        ?>
            <div class="p-2 bg-white rounded-2xl shadow-sm relative flex items-start space-x-2 hover:bg-gray-100 transition-colors duration-200 group">
                <img src="<?php echo htmlspecialchars($message['profile_image']); ?>" alt="Profile Image" class="w-8 h-8 rounded-full">
                <div class="flex-grow relative">
                    <p class="font-bold text-sm"><?php echo htmlspecialchars($message['username']); ?></p>
                    <p class="text-sm"><?php echo nl2br(htmlspecialchars($message['message'])); ?></p>
                    <span class="absolute top-0 right-0 opacity-0 group-hover:opacity-100 transition-opacity duration-200 text-xs text-gray-500">
                        <?php echo $timestamp; ?>
                    </span>
                </div>
                <div class="relative">
                    <button class="text-gray-500 hover:text-gray-700 focus:outline-none" onclick="toggleDropdown(this)">
                        <i class="fas fa-ellipsis-v"></i>
                    </button>
                    <div class="dropdown-menu absolute right-0 mt-2 w-32 bg-white border border-gray-300 rounded-2xl shadow-lg hidden">
                        <a href="#" class="block px-4 py-2 text-gray-700 hover:bg-gray-100 first:rounded-t-2xl">Report</a>
                        <?php if ($isOwnMessage): ?>
                            <a href="#" class="block px-4 py-2 text-gray-700 hover:bg-gray-100" onclick="showEditModal(<?php echo $message['id']; ?>, '<?php echo htmlspecialchars($message['message']); ?>')">Edit</a>
                            <form method="post" action="" class="inline">
                                <input type="hidden" name="delete_message_id" value="<?php echo $message['id']; ?>">
                                <button type="submit" class="block w-full text-left px-4 py-2 text-red-600 hover:bg-red-50 last:rounded-b-xl" onclick="return confirm('Are you sure you want to delete this message?')">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Timestamp Modal -->
    <div id="timestamp-modal" class="hidden fixed bg-gray-900 text-white text-xs py-1 px-2 rounded shadow-lg z-50">
        <span id="timestamp-text"></span>
        <div id="timestamp-arrow" class="absolute w-0 h-0"></div>
    </div>

    <div class="border-t border-gray-200 p-4 bg-white">
        <form id="message-form" class="flex items-center space-x-2">
            <input type="text" 
                   id="message-input" 
                   name="message" 
                   class="flex-grow px-4 py-2 border border-gray-300 rounded-xl focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500" 
                   placeholder="Type a message..." 
                   required>
            <button type="submit" 
                    class="px-4 py-2 bg-blue-500 text-white rounded-xl hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                <i class="fas fa-paper-plane"></i>
            </button>
        </form>
    </div>
  </div>
  
  <!-- Edit Message Modal -->
  <div id="edit-message-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50 hidden">
    <div class="bg-white p-6 rounded-lg shadow-lg w-1/3">
        <h2 class="text-2xl font-semibold mb-4">Edit Message</h2>
        <form method="post" action="">
            <input type="hidden" name="edit_message_id" id="edit_message_id">
            <textarea name="new_message" id="new_message" class="border p-2 w-full mb-4" placeholder="Enter new message" required></textarea>
            <button type="submit" class="bg-green-500 text-white px-4 py-2 rounded mr-2">Save</button>
            <button type="button" class="bg-red-500 text-white px-4 py-2 rounded" onclick="hideEditModal()">Cancel</button>
        </form>
    </div>
  </div>
</div>
  <script>
  function toggleDropdown(button) {
    // Close all other open dropdowns first
    document.querySelectorAll('.dropdown-menu').forEach(menu => {
        if (menu !== button.nextElementSibling) {
            menu.classList.add('hidden');
        }
    });

    const dropdownMenu = button.nextElementSibling;
    dropdownMenu.classList.toggle('hidden');

    // Close dropdown when clicking outside
    const closeDropdown = (e) => {
        if (!button.contains(e.target) && !dropdownMenu.contains(e.target)) {
            dropdownMenu.classList.add('hidden');
            document.removeEventListener('click', closeDropdown);
        }
    };

    if (!dropdownMenu.classList.contains('hidden')) {
        // Small delay to prevent immediate closure
        setTimeout(() => {
            document.addEventListener('click', closeDropdown);
        }, 100);
    }
}

function showEditModal(messageId, messageText) {
    const modal = document.getElementById('edit-message-modal');
    document.getElementById('edit_message_id').value = messageId;
    document.getElementById('new_message').value = messageText;
    modal.classList.remove('hidden');
}

function hideEditModal() {
    document.getElementById('edit-message-modal').classList.add('hidden');
}

// Close dropdown when scrolling chat messages
document.getElementById('chat-messages').addEventListener('scroll', () => {
    document.querySelectorAll('.dropdown-menu').forEach(menu => {
        menu.classList.add('hidden');
    });
});

function startCall(receiverId) {
    // You can implement your call functionality here
    alert('Starting voice call...');
    // Example: window.location.href = `call.php?receiver_id=${receiverId}&type=voice`;
}

function startVideoCall(receiverId) {
    // You can implement your video call functionality here
    alert('Starting video call...');
    // Example: window.location.href = `call.php?receiver_id=${receiverId}&type=video`;
}

function showTimestamp(element, timestamp, isOwnMessage) {
    const modal = document.getElementById('timestamp-modal');
    const text = document.getElementById('timestamp-text');
    const arrow = document.getElementById('timestamp-arrow');
    
    text.textContent = timestamp;
    
    // Get element position
    const rect = element.getBoundingClientRect();
    
    // Position modal
    if (isOwnMessage) {
        modal.style.left = (rect.left - modal.offsetWidth - 10) + 'px';
        arrow.style.borderLeft = '8px solid #111827'; // gray-900
        arrow.style.right = '-8px';
    } else {
        modal.style.left = (rect.right + 10) + 'px';
        arrow.style.borderRight = '8px solid #111827'; // gray-900
        arrow.style.left = '-8px';
    }
    
    modal.style.top = (rect.top + (rect.height / 2) - (modal.offsetHeight / 2)) + 'px';
    arrow.style.borderTop = '8px solid transparent';
    arrow.style.borderBottom = '8px solid transparent';
    arrow.style.top = '50%';
    arrow.style.transform = 'translateY(-50%)';
    arrow.style.position = 'absolute';
    
    modal.classList.remove('hidden');
}

function hideTimestamp() {
    const modal = document.getElementById('timestamp-modal');
    modal.classList.add('hidden');
}
  </script>
</body>
</html>

<script>
// Toggle dropdown menu
document.getElementById('profileImage').addEventListener('click', function() {
  const dropdownMenu = this.nextElementSibling;
  dropdownMenu.classList.toggle('hidden');
});

// Fetch the list of bubbles the user has joined
function fetchJoinedBubbles() {
    fetch("joinedBubble.php")
    .then(response => response.json())
    .then(data => {
        const bubbleList = document.getElementById("bubble-list");
        bubbleList.innerHTML = "";
        data.bubbles.forEach(bubble => {
            const bubbleItem = document.createElement("li");
            bubbleItem.className = "bubble-container relative";
            bubbleItem.innerHTML = `
                <a href="bubblePage.php?bubble_id=${bubble.id}" class="block p-2 text-center transform hover:scale-105 transition-transform duration-200 relative">
                    <img src="data:image/jpeg;base64,${bubble.profile_image}" alt="${bubble.bubble_name}" class="w-10 h-10 rounded-full mx-auto">
                    <div class="bubble-name-modal absolute left-full top-1/2 transform -translate-y-1/2 ml-2 bg-gray-800 text-white text-xs rounded px-2 py-1 opacity-0 transition-opacity duration-200">${bubble.bubble_name}</div>
                </a>
            `;
            bubbleList.appendChild(bubbleItem);
        });

        // Add event listeners to show/hide the modal on hover
        document.querySelectorAll('.bubble-container a').forEach(anchor => {
            anchor.addEventListener('mouseenter', function() {
                const modal = this.querySelector('.bubble-name-modal');
                modal.classList.remove('opacity-0');
                modal.classList.add('opacity-100');
            });
            anchor.addEventListener('mouseleave', function() {
                const modal = this.querySelector('.bubble-name-modal');
                modal.classList.remove('opacity-100');
                modal.classList.add('opacity-0');
            });
        });
    })
    .catch(error => {
        console.error("Error fetching joined bubbles:", error);
    });
}

  document.addEventListener("DOMContentLoaded", fetchJoinedBubbles);

  // Add event listener to user list items
  document.querySelectorAll('.sidebarb ul li').forEach(item => {
    item.addEventListener('click', function() {
      const userId = this.getAttribute('data-user-id');
      window.location.href = `indexBubble.php?receiver_id=${userId}`;
    });
  });

    // Send chat message
    document.getElementById('message-form').addEventListener('submit', function(event) {
      event.preventDefault();
      const messageInput = document.getElementById('message-input');
      const message = messageInput.value;
      const receiverId = <?php echo $receiver_id; ?>;
      const userId = <?php echo $_SESSION['user_id']; ?>;

      fetch('sendMessage.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ sender_id: userId, receiver_id: receiverId, content: message })
      })
      .then(response => response.json())
      .then(data => {
      if (data.success) {
        messageInput.value = '';
        window.location.href = `indexBubble.php?receiver_id=${receiverId}`;
      } else {
        console.error('Error sending message');
      }
      })
      .catch(error => console.error('Error sending message:', error));
    });
  </script>
</html>
