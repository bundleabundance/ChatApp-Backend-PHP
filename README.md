# ChatApp-Backend-PHP
Welcome to the Chat Application PHP Project! This project aims to create a robust chat application using PHP, Slim framework, and a database to store groups, users, and messages. The application provides basic chat functionality, user authentication, and group management features. Please note that the project is currently under development, and you might encounter issues due to package dependencies.

Prerequisites
Make sure you have the following software and tools installed on your system:

PHP 7.x or higher
Composer (for managing PHP dependencies)
MySQL or any other compatible database system
Slim framework
PHPUnit (for running tests)
Installation and Setup
Clone the repository to your local machine:
`
git clone https://github.com/yourusername/chat-application-php.git`
Navigate to the project directory:
`
cd BreadcrumbsChatApp-Backend-PHP`

Install project dependencies using Composer:
`
composer install`

Configure your database connection by updating the config/database.php file with your database credentials.

Run the database migrations to set up the database schema:
`
php migrate.php`

Start the development server:
`
php -S localhost:8000 -t public`

Now you can access the chat application at http://localhost:8000.

Usage
Authentication Middleware
The project includes middleware for user authentication. To access protected routes, you need to include an authentication token in the request headers.

Example:

`
Authorization: Bearer your_auth_token`

#Chat Functionality
User Registration:
Users can register for an account with a unique username and password.

User Login:
Registered users can log in to their accounts.

Group Creation:
Authenticated users can create new chat groups.

Joining/Leaving Groups:
Users can join or leave existing chat groups.

Sending Messages:
Users can send messages to groups they are part of.



Running Tests
To run tests, execute the following command from the project root:
`
vendor/bin/phpunit`

Known Issues
List any known issues or problems you are facing with the project dependencies.
Contributing
If you encounter issues or have suggestions for improvements, please create an issue on the repository. Pull requests are also welcome.
