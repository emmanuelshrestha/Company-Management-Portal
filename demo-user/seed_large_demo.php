<?php
ini_set('max_execution_time', 0); // 0 = unlimited
// seed_large_demo.php
// This script seeds 1000 users, random friendships, and posts
// Uses your existing db.php for connection

require_once __DIR__ . '/../config/db.php'; // adjust path if necessary

// Helper functions
function randomName() {
    $first = [
    'James','John','Robert','Michael','William','David','Richard','Joseph','Thomas','Charles',
    'Christopher','Daniel','Matthew','Anthony','Mark','Donald','Steven','Paul','Andrew','Joshua',
    'Emily','Emma','Olivia','Ava','Sophia','Isabella','Mia','Charlotte','Amelia','Harper',
    'Evelyn','Abigail','Ella','Scarlett','Grace','Chloe','Victoria','Riley','Aria','Lily',
    'Henry','Alexander','Jack','Owen','Luke','Gabriel','Samuel','Carter','Jayden','Wyatt',
    'Nathan','Caleb','Ryan','Isaac','Christian','Dylan','Jonathan','Aaron','Eli','Connor',
    'Madison','Luna','Sofia','Avery','Ella','Camila','Penelope','Hannah','Lily','Addison',
    'Leah','Audrey','Aubrey','Brooklyn','Bella','Nora','Hazel','Violet','Aurora','Savannah',
    'Eleanor','Skylar','Paisley','Claire','Lucy','Anna','Stella','Natalie','Zoe','Allison',
    'Samuel','Nicholas','Ethan','Liam','Benjamin','Mason','Logan','Jackson','Sebastian','Jack',
    'Grayson','Hunter','Isaiah','Jeremiah','Tyler','Cameron','Dominic','Aaron','Angel','Colton'
    ];
    $last = [
    'Smith','Johnson','Williams','Brown','Jones','Miller','Davis','Garcia','Rodriguez','Wilson',
    'Martinez','Anderson','Taylor','Thomas','Hernandez','Moore','Martin','Jackson','Thompson','White',
    'Lopez','Lee','Gonzalez','Harris','Clark','Lewis','Robinson','Walker','Perez','Hall',
    'Young','Allen','Sanchez','Wright','King','Scott','Green','Baker','Adams','Nelson',
    'Hill','Ramirez','Campbell','Mitchell','Roberts','Carter','Phillips','Evans','Turner','Torres',
    'Parker','Collins','Edwards','Stewart','Flores','Morris','Nguyen','Murphy','Rivera','Cook',
    'Rogers','Morgan','Peterson','Cooper','Reed','Bailey','Bell','Gomez','Kelly','Howard',
    'Ward','Cox','Diaz','Richardson','Wood','Watson','Brooks','Bennett','Gray','James',
    'Reyes','Cruz','Hughes','Price','Myers','Long','Foster','Sanders','Ross','Morales',
    'Powell','Sullivan','Russell','Ortiz','Jenkins','Gutierrez','Perry','Butler','Barnes','Fisher'
    ];

    return $first[array_rand($first)] . ' ' . $last[array_rand($last)];
}
function randomEmail($name, $i) {
    $clean = strtolower(str_replace(' ', '.', $name));
    return $clean.$i.'@example.test';
}
function randomPassword($length=10) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    $str = '';
    for ($i=0;$i<$length;$i++) $str .= $chars[rand(0, strlen($chars)-1)];
    return $str;
}
function randomPostContent() {
   $samples = [
    "Just had the most amazing breakfast ever!",
    "Feeling grateful for today.",
    "Watching my favorite TV show tonight.",
    "Can't believe how fast this week went by!",
    "Exploring the city, love the vibes.",
    "Coffee is life ☕",
    "Went for a long walk in the park.",
    "This weather is perfect for reading.",
    "Bought a new book, so excited to start!",
    "Life is too short to be stressed all the time.",
    "Trying out a new recipe today.",
    "Weekend vibes activated!",
    "Listening to some chill music.",
    "My cat is so cute 😻",
    "Just finished a great workout session.",
    "Feeling productive and motivated.",
    "Time to relax with some tea.",
    "Movie night with friends!",
    "Nature is so calming 🌿",
    "Learning a new hobby, wish me luck!",
    "Caught up with an old friend today.",
    "Had a lazy day, sometimes that’s needed.",
    "Trying a new restaurant in town.",
    "Feeling creative today, sketching a bit.",
    "Life feels good right now.",
    "Just posted my first photo on Instagram!",
    "Sunsets are my favorite 🌅",
    "Baking cookies today 🍪",
    "Spending time with family is the best.",
    "Music really lifts my mood.",
    "Enjoying some ice cream on this hot day.",
    "Travel plans are finally happening!",
    "Meditation helps me stay calm.",
    "Trying to limit screen time today.",
    "This playlist is fire 🔥",
    "Cooking dinner for friends tonight.",
    "A quiet night at home, loving it.",
    "Feeling nostalgic looking at old photos.",
    "Hiking adventure today was amazing!",
    "New coffee shop discovered, highly recommend!",
    "Feeling inspired by today’s sunset.",
    "Went to a concert, had a blast!",
    "Planning my next adventure.",
    "Just finished painting my room.",
    "Laughing way too much today 😂",
    "Feeling accomplished after finishing a project.",
    "Listening to a podcast about productivity.",
    "Lazy Sunday mornings are the best.",
    "Trying to learn a new language.",
    "Went swimming today, refreshing!",
    "Trying yoga for the first time.",
    "Beach day with friends 🏖️",
    "Walking around the city and taking photos.",
    "Rainy days make me want to read.",
    "Got a new haircut today!",
    "Feeling happy and content.",
    "Enjoying some quiet time alone.",
    "Movie marathon all day.",
    "Just adopted a puppy 🐶",
    "Exploring a new neighborhood today.",
    "Trying out painting, surprisingly fun!",
    "Feeling motivated to start a new project.",
    "Just got a new phone, excited to try it out.",
    "Weekend getaway planned!",
    "Picnic in the park with friends.",
    "Feeling a bit tired but accomplished.",
    "Had the best ice cream sundae today.",
    "Enjoying the beautiful autumn leaves 🍁",
    "Learning to play the guitar.",
    "Trying to bake bread for the first time.",
    "Morning jog feels great!",
    "Spending time with loved ones.",
    "Just finished a puzzle, feeling proud.",
    "Relaxing with some music and coffee.",
    "Binge-watching a new series tonight.",
    "Feeling adventurous, trying new food.",
    "Visited a museum today, very inspiring.",
    "Working on a DIY home project.",
    "Had a productive day at work!",
    "Trying out photography today.",
    "Feeling excited for the weekend!",
    "Enjoying a lazy afternoon nap.",
    "Just wrote in my journal.",
    "Tried a new dessert recipe, it’s delicious!",
    "Went for a bike ride around the neighborhood.",
    "Feeling proud of my progress this month.",
    "Relaxing at the beach, listening to waves.",
    "Finally organized my workspace!",
    "Enjoying a cup of tea while reading.",
    "Had a fun board game night with friends.",
    "Feeling motivated to start the week strong.",
    "Exploring new coffee blends today.",
    "Just discovered a new favorite artist.",
    "Watching the stars tonight ✨",
    "Trying out a new workout routine.",
    "Visited a botanical garden today.",
    "Feeling happy to reconnect with old friends.",
    "Tried meditation for the first time.",
    "Weekend hiking trip was amazing!",
    "Just found a new favorite restaurant.",
    "Feeling energized after a morning run.",
    "Watching old movies tonight.",
    "Trying a new hairstyle, love it!",
    "Enjoying a quiet evening with a book.",
    "Feeling inspired by nature.",
    "Cooked a big meal for the family.",
    "Just learned a new recipe, yum!",
    "Spending time at a local cafe.",
    "Feeling relaxed after a spa day.",
    "Weekend road trip planned!",
    "Trying a new craft project today.",
    "Enjoying a sunset walk on the beach.",
    "Feeling grateful for my friends and family.",
    "Just started a new series, so good!",
    "Had a fun game night with friends.",
    "Exploring a new city this weekend.",
    "Feeling motivated to exercise regularly.",
    "Tried a new coffee flavor today.",
    "Visited a new park today, very peaceful.",
    "Just finished reading a fantastic book.",
    "Weekend brunch with friends was amazing.",
    "Enjoying a peaceful evening at home.",
    "Trying out a new hobby, painting.",
    "Feeling happy after a productive day.",
    "Went to a farmers market today.",
    "Trying new music genres today.",
    "Feeling relaxed after yoga session.",
    "Had a great workout today!",
    "Just discovered a cool new app.",
    "Spending time with pets today.",
    "Feeling adventurous, trying new food.",
    "Went for a scenic drive today.",
    "Enjoying a quiet evening with some tea.",
    "Just learned something new today!",
    "Weekend DIY project completed.",
    "Feeling inspired to start a blog.",
    "Trying a new restaurant in town.",
    "Relaxing with a good movie tonight.",
    "Just finished a fun puzzle.",
    "Feeling happy with small achievements.",
    "Trying out a new dessert recipe.",
    "Visited a local museum today.",
    "Feeling productive and motivated today.",
    "Went for a long nature walk today.",
    "Enjoying some fresh air and sunshine.",
    "Just finished a creative writing session.",
    "Trying out a new sport today.",
    "Feeling grateful for the little things."
    ];

    return $samples[array_rand($samples)];
}

// 1️⃣ Insert 1000 users
$userIds = [];

echo "Inserting 1000 users...\n";
for ($i=1; $i<=1000; $i++) {
    $name = randomName();
    $email = randomEmail($name, $i);
    $plainPass = randomPassword(10);
    $hash = password_hash($plainPass, PASSWORD_BCRYPT);
    $status = 'Verified';
    $token = bin2hex(random_bytes(8));
    $created_at = date('Y-m-d H:i:s');

    $stmt = $conn->prepare("
        INSERT INTO users (name,email,password,status,verification_token,created_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('ssssss', $name, $email, $hash, $status, $token, $created_at);
    $stmt->execute();
    $id = $stmt->insert_id;
    $userIds[] = $id;

    if ($i <= 10) {
        echo "User {$id}: {$email} plain_password={$plainPass}\n"; // only first 10
    }
    $stmt->close();
}
echo "Inserted 1000 users.\n";

// 2️⃣ Insert random friendships
echo "Inserting random friendships...\n";
$stmt = $conn->prepare("INSERT INTO friends (user_id, friend_id, status) VALUES (?, ?, ?)");
foreach ($userIds as $uid) {
    $numFriends = rand(5,10);
    $added = [];
    for ($j=0; $j<$numFriends; $j++) {
        $friend = $userIds[array_rand($userIds)];
        if ($friend == $uid || isset($added[$friend])) continue;
        $added[$friend] = true;
        $status = rand(0,1) ? 'approved' : 'pending';
        try {
            $stmt->bind_param('iis', $uid, $friend, $status);
            $stmt->execute();
        } catch (Exception $e) {
            // skip duplicates
        }
    }
}
$stmt->close();
echo "Random friendships inserted.\n";

// 3️⃣ Insert random posts
echo "Inserting posts...\n";
$stmt = $conn->prepare("INSERT INTO posts (user_id, content, created_at) VALUES (?, ?, ?)");
foreach ($userIds as $uid) {
    $numPosts = rand(1,3);
    for ($p=0;$p<$numPosts;$p++) {
        $content = randomPostContent();
        $created_at = date('Y-m-d H:i:s');
        $stmt->bind_param('iss', $uid, $content, $created_at);
        $stmt->execute();
    }
}
$stmt->close();

echo "Random posts inserted.\n";
echo "Seeding completed successfully.\n";
?>
