<?php
function displayAvatar($user, $size = 'medium') {
    $sizes = [
        'small' => '30px',
        'medium' => '40px', 
        'large' => '50px',
        'xlarge' => '60px',
        'profile' => '150px'
    ];
    
    $sizePx = $sizes[$size] ?? '40px';
    
    if (!empty($user['profile_picture'])) {
        return '<div class="user-avatar" style="width: ' . $sizePx . '; height: ' . $sizePx . '; background-image: url(../../uploads/profile_pictures/' . htmlspecialchars($user['profile_picture']) . '); background-size: cover; background-position: center; border-radius: 50%;"></div>';
    } else {
        return '<div class="user-avatar" style="width: ' . $sizePx . '; height: ' . $sizePx . '; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; border-radius: 50%; font-size: ' . ($size === 'profile' ? '48px' : ($size === 'xlarge' ? '20px' : '16px')) . ';">' . strtoupper(substr($user['name'] ?? 'U', 0, 1)) . '</div>';
    }
}
?>