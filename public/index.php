<?php
require __DIR__ . '/vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Slim\Factory\ServerRequestFactory;
use Slim\Factory\ResponseFactory;
use SQLite3;

class ChatAppTest extends TestCase {
    private $app;
    private $db;

    public function setUp(): void {
        $this->app = AppFactory::create();
        $this->app->addErrorMiddleware(false, false, false);

        $this->db = new SQLite3(':memory:');
        $this->db->exec('CREATE TABLE groups (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $this->db->exec('CREATE TABLE messages (id INTEGER PRIMARY KEY AUTOINCREMENT, group_id INTEGER, user_id INTEGER, message TEXT, timestamp DATETIME DEFAULT CURRENT_TIMESTAMP)');

        // Inject the database into the app container for the API endpoints
        $this->app->getContainer()->set('db', $this->db);
    }

    //tests for each API endpoint
    public function testCreateGroup(): void {
        $request = ServerRequestFactory::createServerRequest('POST', '/groups', ['name' => 'Test Group']);
        $response = $this->app->handle($request);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testListGroups(): void {
        $request = ServerRequestFactory::createServerRequest('GET', '/groups');
        $response = $this->app->handle($request);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testJoinGroup(): void {
        // Assuming group with ID 1 exists
        $request = ServerRequestFactory::createServerRequest('POST', '/groups/1/join');
        $response = $this->app->handle($request);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSendMessage(): void {
        // Assuming group with ID 1 exists
        $request = ServerRequestFactory::createServerRequest('POST', '/groups/1/messages', ['message' => 'Hello, World!']);
        $response = $this->app->handle($request);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testListMessages(): void {
        // Assuming group with ID 1 exists
        $request = ServerRequestFactory::createServerRequest('GET', '/groups/1/messages');
        $response = $this->app->handle($request);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSyncMessages(): void {
        // Assuming group with ID 1 exists
        $request = ServerRequestFactory::createServerRequest('GET', '/groups/1/messages/sync', ['lastMessageId' => 0]);
        $response = $this->app->handle($request);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function tearDown(): void {
        $this->db->close();
    }
}

$app = AppFactory::create();

// Database Connection
try {
    $db = new SQLite3('chat.db');
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}



// Create a JWT secret key (replace with a secure secret in production)
define('JWT_SECRET', 'supersecretkey');

// Middleware for user authentication
$authMiddleware = function ($request, $handler) {
    $token = $request->getHeader('Authorization')[0] ?? '';
    if (empty($token)) {
        throw new HttpBadRequestException($request, "Token missing");
    }

    try {
        $decoded = JWT::decode($token, JWT_SECRET, array('HS256'));
        // Validate user based on the decoded user ID
        $userId = $decoded->user_id;
        $stmt = $db->prepare("SELECT * FROM users WHERE id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        if (!$result->fetchArray()) {
            throw new HttpUnauthorizedException($request, "Invalid user");
        }
        // Add the user ID to the request attributes for further use
        $request = $request->withAttribute('userId', $userId);
    } catch (Exception $e) {
            throw new HttpBadRequestException($request, "Invalid token");
    }

    $response = $handler->handle($request);
    return $response;
};

// Middleware to inject user ID into the request attributes
$userIdMiddleware = function ($request, $handler) {
    $token = $request->getHeader('Authorization')[0] ?? '';
    try {
        $decoded = JWT::decode($token, JWT_SECRET, array('HS256'));
        $request = $request->withAttribute('user_id', $decoded->user_id);
    } catch (Exception $e) {
        // Do nothing if the token is invalid or missing
    }

    $response = $handler->handle($request);
    return $response;
};

// Middleware for protected routes (requires authentication)
$protectedRoutes = function (RouteCollectorProxy $group) use ($authMiddleware, $userIdMiddleware) {
    // API endpoints that require authentication
    $group->post('/groups', 'ChatController:createGroup')->add($authMiddleware);
    $group->post('/groups/{groupId}/join', 'ChatController:joinGroup')->add($authMiddleware);
    $group->post('/groups/{groupId}/messages', 'ChatController:sendMessage')->add($authMiddleware);
};

// Middleware for public routes (no authentication required)
$publicRoutes = function (RouteCollectorProxy $group) use ($userIdMiddleware) {
    // API endpoints that do not require authentication
    $group->get('/groups', 'ChatController:listGroups');
    $group->get('/groups/{groupId}/messages', 'ChatController:listMessages');
    $group->get('/groups/{groupId}/messages/sync', 'ChatController:syncMessages')->add($userIdMiddleware);
};

// Define the routes using the middleware
$app->group('/api', function (RouteCollectorProxy $group) use ($protectedRoutes, $publicRoutes) {
    // Public routes
    $group->group('/public', $publicRoutes);
    // Protected routes
    $group->group('/protected', $protectedRoutes);
});


// API endpoints
// User Registration
$app->post('/register', function ($request, $response, $args) use ($db) {
    $data = $request->getParsedBody();
    $username = $data['username'];


    // Insert user data into the database
    $stmt = $db->prepare("INSERT INTO users (username) VALUES (:username)");
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $stmt->execute();

    return $response->withJson(["message" => "User registered successfully"]);
});


// Create a Chat Group
$app->post('/groups', function ($request, $response, $args) use ($db) {
    $data = $request->getParsedBody();
    $name = $data['name'];
    
    if (empty($name)) {
        throw new HttpBadRequestException($request, "Group name is required");
    }
    
    $stmt = $db->prepare("INSERT INTO groups (name) VALUES (:name)");
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->execute();
    
    return $response->withJson(["message" => "Chat group created successfully"]);
});

// List All Chat Groups
$app->get('/groups', function ($request, $response, $args) use ($db) {
    $results = $db->query('SELECT * FROM groups');
    $groups = [];
    while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
        $groups[] = $row;
    }
    return $response->withJson($groups);
});

// Join a Chat Group
$app->post('/groups/{groupId}/join', function ($request, $response, $args) use ($db) {
    $groupId = $args['groupId'];

     // Validate user using middleware
     $userId = $request->getAttribute('userId');
    
    // Check if the group exists (validation logic)
    $stmt = $db->prepare("SELECT * FROM groups WHERE id = :group_id");
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if (!$result->fetchArray()) {
        // Group does not exist, return error response
        return $response->withJson(["error" => "Group not found"], 404);
    }
    
    return $response->withJson(["message" => "Joined the chat group successfully"]);
})->add($authMiddleware);

// Send a Message in a Group
$app->post('/groups/{groupId}/messages', function ($request, $response, $args) use ($db) {
    $groupId = $args['groupId'];
    $data = $request->getParsedBody();
    $message = $data['message'];
    
    if (empty($message)) {
        throw new HttpBadRequestException($request, "Message cannot be empty");
    }
    
    // Implement user validation logic (e.g., user authentication)
    $userId = $request->getAttribute('userId');

    // Check if the group exists (validation logic)
    $stmt = $db->prepare("SELECT * FROM groups WHERE id = :group_id");
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if (!$result->fetchArray()) {
        // Group does not exist, return error response
        return $response->withJson(["error" => "Group not found"], 404);
    }
    
    
    // Insert the message into the database with groupId
    $stmt = $db->prepare("INSERT INTO messages (group_id, user_id, message) VALUES (:group_id, :user_id, :message)");
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', 1, SQLITE3_INTEGER); // User ID (replace with authenticated user ID)
    $stmt->bindValue(':message', $message, SQLITE3_TEXT);
    $stmt->execute();
    
    return $response->withJson(["message" => "Message sent successfully"]);
})->add($authMiddleware);

// List Messages in a Group
$app->get('/groups/{groupId}/messages', function ($request, $response, $args) use ($db) {
    $groupId = $args['groupId'];
    

    // Check if the group exists (validation logic)
    $stmt = $db->prepare("SELECT * FROM groups WHERE id = :group_id");
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if (!$result->fetchArray()) {
        // Group does not exist, return error response
        return $response->withJson(["error" => "Group not found"], 404);
    }

    
    // Retrieve messages from the database based on groupId
    $stmt = $db->prepare("SELECT * FROM messages WHERE group_id = :group_id ORDER BY timestamp");
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $results = $stmt->execute();
    
    $messages = [];
    while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
        $messages[] = $row;
    }
    
    return $response->withJson($messages);
});

// Synchronized Messaging
$app->get('/groups/{groupId}/messages/sync', function ($request, $response, $args) use ($db) {
    $groupId = $args['groupId'];
    $lastMessageId = $request->getQueryParam('lastMessageId', 0);
    

    // Check if the group exists (validation logic)
    $stmt = $db->prepare("SELECT * FROM groups WHERE id = :group_id");
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if (!$result->fetchArray()) {
        // Group does not exist, return error response
        return $response->withJson(["error" => "Group not found"], 404);
    }

      
    // Retrieve new messages since lastMessageId from the database based on groupId
    $stmt = $db->prepare("SELECT * FROM messages WHERE group_id = :group_id AND id > :lastMessageId ORDER BY timestamp");
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $stmt->bindValue(':lastMessageId', $lastMessageId, SQLITE3_INTEGER);
    $results = $stmt->execute();
    
    $newMessages = [];
    while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
        $newMessages[] = $row;
    }
    
    return $response->withJson($newMessages);
});

$app->run();