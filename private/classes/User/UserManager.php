<?php

/*
 * PHP-Auth (https://github.com/delight-im/PHP-Auth)
 * Copyright (c) delight.im (https://www.delight.im/)
 * Licensed under the MIT License (https://opensource.org/licenses/MIT)
 */

namespace glFusion\User;

if (!defined ('GVERSION')) {
    die ('This file can not be used on its own.');
}

use glFusion\User\Exceptions;
use Delight\Base64\Base64;
use Delight\Cookie\Session;
use glFusion\Database\Database;
use glFusion\Log\Log;


/**
 * Abstract base class for components implementing user management
 *
 * @internal
 */
abstract class UserManager {

	/** @var string session field for whether the client is currently signed in */
	const SESSION_FIELD_LOGGED_IN = 'auth_logged_in';
	/** @var string session field for the ID of the user who is currently signed in (if any) */
	const SESSION_FIELD_USER_ID = 'auth_user_id';
	/** @var string session field for the email address of the user who is currently signed in (if any) */
	const SESSION_FIELD_EMAIL = 'auth_email';
	/** @var string session field for the display name (if any) of the user who is currently signed in (if any) */
	const SESSION_FIELD_USERNAME = 'auth_username';
	/** @var string session field for the status of the user who is currently signed in (if any) as one of the constants from the {@see Status} class */
	const SESSION_FIELD_STATUS = 'auth_status';
	/** @var string session field for the roles of the user who is currently signed in (if any) as a bitmask using constants from the {@see Role} class */
	const SESSION_FIELD_ROLES = 'auth_roles';
	/** @var string session field for whether the user who is currently signed in (if any) has been remembered (instead of them having authenticated actively) */
	const SESSION_FIELD_REMEMBERED = 'auth_remembered';
	/** @var string session field for the UNIX timestamp in seconds of the session data's last resynchronization with its authoritative source in the database */
	const SESSION_FIELD_LAST_RESYNC = 'auth_last_resync';
	/** @var string session field for the counter that keeps track of forced logouts that need to be performed in the current session */
	const SESSION_FIELD_FORCE_LOGOUT = 'auth_force_logout';
	/** @var string session field for the timezone of the user who is currently signed in (if any) */
	const SESSION_FIELD_TIMEZONE = 'auth_timezone';
	/** @var string session field for admin session (if needed) */
	const SESSION_FIELD_ADMIN_SESSION = 'auth_admin_session';

	/**
	 * Creates a random string with the given maximum length
	 *
	 * With the default parameter, the output should contain at least as much randomness as a UUID
	 *
	 * @param int $maxLength the maximum length of the output string (integer multiple of 4)
	 * @return string the new random string
	 */
	public static function createRandomString($maxLength = 24) {
		// calculate how many bytes of randomness we need for the specified string length
		$bytes = \floor((int) $maxLength / 4) * 3;

		// get random data
		$data = \openssl_random_pseudo_bytes($bytes);

		// return the Base64-encoded result
		return Base64::encodeUrlSafe($data);
	}

	/**
	 */
	protected function __construct() {

    }

	/**
	 * Creates a new user
	 *
	 * If you want the user's account to be activated by default, pass `null` as the callback
	 *
	 * If you want to make the user verify their email address first, pass an anonymous function as the callback
	 *
	 * The callback function must have the following signature:
	 *
	 * `function ($selector, $token)`
	 *
	 * Both pieces of information must be sent to the user, usually embedded in a link
	 *
	 * When the user wants to verify their email address as a next step, both pieces will be required again
	 *
	 * @param bool $requireUniqueUsername whether it must be ensured that the username is unique
	 * @param string $email the email address to register
	 * @param string $password the password for the new account
	 * @param string|null $username (optional) the username that will be displayed
	 * @param callable|null $callback (optional) the function that sends the confirmation email to the user
	 * @return int the ID of the user that has been created (if any)
	 * @throws InvalidEmailException if the email address has been invalid
	 * @throws InvalidPasswordException if the password has been invalid
	 * @throws UserAlreadyExistsException if a user with the specified email address already exists
	 * @throws DuplicateUsernameException if it was specified that the username must be unique while it was *not*
	 * @throws AuthError if an internal problem occurred (do *not* catch)
	 *
	 * @see confirmEmail
	 * @see confirmEmailAndSignIn
	 */
	protected function createUserInternal($requireUniqueUsername, $email, $password, $username = null, callable $callback = null) {
		global $_TABLES;

		\ignore_user_abort(true);

		$email = self::validateEmailAddress($email);
		$password = self::validatePassword($password);

		$username = isset($username) ? \trim($username) : null;

		// if the supplied username is the empty string or has consisted of whitespace only
		if ($username === '') {
			// this actually means that there is no username
			$username = null;
		}

		// if the uniqueness of the username is to be ensured
		if ($requireUniqueUsername) {
			// if a username has actually been provided
			if ($username !== null) {
				// count the number of users who do already have that specified username
                $occurrencesOfUsername = Database::getInstance()->conn->fetchColumn(
                    "SELECT COUNT(*) FROM {$_TABLES['users']} WHERE username = ?",
                    [ $username ],
                    0
                );

				// if any user with that username does already exist
				if ($occurrencesOfUsername > 0) {
					// cancel the operation and report the violation of this requirement
					throw new DuplicateUsernameException();
				}
			}
		}
//@TODO - use our own passwor encryption option
		$password = \password_hash($password, \PASSWORD_DEFAULT);
		$verified = \is_callable($callback) ? 0 : 1;

		try {
			Database::getInstance()->conn->insert(
                $_TABLES['users'],
				[
					'email'    => $email,
					'password' => $password,
					'username' => $username,
					'verified' => $verified,
					'regdate'  => \time()
				],
                [
                    Database::STRING,
                    Database::STRING,
                    Database::STRING,
                    Database::INTEGER,
					Database::INTEGER
				]
            );
		}
		// if we have a duplicate entry
		catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
			throw new UserAlreadyExistsException();
		}
		catch (\Error $e) {
			throw new DatabaseError($e->getMessage());
		}

		$newUserId = (int) Database::getInstance()->conn->lastInsertId();

		if ($verified === 0) {
			$this->createConfirmationRequest($newUserId, $email, $callback);
		}

		return $newUserId;
	}

	/**
	 * Updates the given user's password by setting it to the new specified password
	 *
	 * @param int $userId the ID of the user whose password should be updated
	 * @param string $newPassword the new password
	 * @throws UnknownIdException if no user with the specified ID has been found
	 * @throws AuthError if an internal problem occurred (do *not* catch)
	 */
	protected function updatePasswordInternal($userId, $newPassword) {
		$newPassword = \password_hash($newPassword, \PASSWORD_DEFAULT);

		try {
			$affected = $this->db->update(
				$this->makeTableNameComponents('users'),
				[ 'password' => $newPassword ],
				[ 'uid' => $userId ]
			);

			if ($affected === 0) {
				throw new UnknownIdException();
			}
		}
		catch (\Error $e) {
			throw new DatabaseError($e->getMessage());
		}
	}

	/**
	 * Called when a user has successfully logged in
	 *
	 * This may happen via the standard login, via the "remember me" feature, or due to impersonation by administrators
	 *
	 * @param int $userId the ID of the user
	 * @param string $email the email address of the user
	 * @param string $username the display name (if any) of the user
	 * @param int $status the status of the user as one of the constants from the {@see Status} class
	 * @param int $roles the roles of the user as a bitmask using constants from the {@see Role} class
	 * @param int $forceLogout the counter that keeps track of forced logouts that need to be performed in the current session
	 * @param bool $remembered whether the user has been remembered (instead of them having authenticated actively)
	 * @throws AuthError if an internal problem occurred (do *not* catch)
	 */
	protected function onLoginSuccessful($userId, $email, $username, $status, $roles, $forceLogout, $remembered) {
		// re-generate the session ID to prevent session fixation attacks (requests a cookie to be written on the client)
		Session::regenerate(true);

		// save the user data in the session variables maintained by this library
		$_SESSION[self::SESSION_FIELD_LOGGED_IN] = true;
		$_SESSION[self::SESSION_FIELD_USER_ID] = (int) $userId;
		$_SESSION[self::SESSION_FIELD_EMAIL] = $email;
		$_SESSION[self::SESSION_FIELD_USERNAME] = $username;
		$_SESSION[self::SESSION_FIELD_STATUS] = (int) $status;
		$_SESSION[self::SESSION_FIELD_ROLES] = (int) $roles;
		$_SESSION[self::SESSION_FIELD_FORCE_LOGOUT] = (int) $forceLogout;
		$_SESSION[self::SESSION_FIELD_REMEMBERED] = $remembered;
		$_SESSION[self::SESSION_FIELD_LAST_RESYNC] = \time();
	}

	/**
	 * Returns the requested user data for the account with the specified username (if any)
	 *
	 * You must never pass untrusted input to the parameter that takes the column list
	 *
	 * @param string $username the username to look for
	 * @param array $requestedColumns the columns to request from the user's record
	 * @return array the user data (if an account was found unambiguously)
	 * @throws UnknownUsernameException if no user with the specified username has been found
	 * @throws AmbiguousUsernameException if multiple users with the specified username have been found
	 * @throws AuthError if an internal problem occurred (do *not* catch)
	 */
	protected function getUserDataByUsername($username, array $requestedColumns) {
        global $_TABLES;
		try {
			$projection = \implode(', ', $requestedColumns);

            $users = Database::getInstance()->conn->fetchAll(
                'SELECT ' . $projection . ' FROM ' . $_TABLES['users'] . ' WHERE username = ? LIMIT 2 OFFSET 0',
                [ $username ],
                [ Database::STRING ]
            );
		}
		catch (\Throwable $e) {
			throw new DatabaseError($e->getMessage());
		}

		if (empty($users)) {
            return false;
			throw new UnknownUsernameException();
		}
		else {
			if (\count($users) === 1) {
				return $users[0];
			}
			else {
				throw new AmbiguousUsernameException();
			}
		}
	}

	/**
	 * Validates an email address
	 *
	 * @param string $email the email address to validate
	 * @return string the sanitized email address
	 * @throws InvalidEmailException if the email address has been invalid
	 */
	protected static function validateEmailAddress($email) {
		if (empty($email)) {
			throw new InvalidEmailException();
		}

		$email = \trim($email);

		if (!\filter_var($email, \FILTER_VALIDATE_EMAIL)) {
			throw new InvalidEmailException();
		}

		return $email;
	}

	/**
	 * Validates a password
	 *
	 * @param string $password the password to validate
	 * @return string the sanitized password
	 * @throws InvalidPasswordException if the password has been invalid
	 */
	protected static function validatePassword($password) {
		if (empty($password)) {
			throw new InvalidPasswordException();
		}

		$password = \trim($password);

		if (\strlen($password) < 1) {
			throw new InvalidPasswordException();
		}

		return $password;
	}

    /**
     * Check email address against a list of domains
     *
     * Checks if the given email's domain part matches one of the entries in a
     * comma-separated list of domain names (regular expressions are allowed).
     *
     * @param string $email the users email address
	 * @param string $domain_list a list of domains (comma separated) to search
	 * @throws DisallowedDomainException if the domain is found in the domain list
    */
    protected static function checkDisallowedDomains($email, $domain_list)
    {
        $match_found = false;

        if (!empty ($domain_list)) {
            $domains = explode (',', $domain_list);

            // Note: We should already have made sure that $email is a valid address
            $email_domain = substr ($email, strpos ($email, '@') + 1);
            $email_domain = trim($email_domain);

            foreach ($domains as $domain) {
                $domain = trim($domain);
                if (preg_match ("#$domain#i", $email_domain)) {
                    throw new DisallowedDomainException();
                }
            }
        }
    }

	/**
	 * Creates a request for email confirmation
	 *
	 * The callback function must have the following signature:
	 *
	 * `function ($selector, $token)`
	 *
	 * Both pieces of information must be sent to the user, usually embedded in a link
	 *
	 * When the user wants to verify their email address as a next step, both pieces will be required again
	 *
	 * @param int $userId the user's ID
	 * @param string $email the email address to verify
	 * @param callable $callback the function that sends the confirmation email to the user
	 * @throws AuthError if an internal problem occurred (do *not* catch)
	 */
	protected function createConfirmationRequest($userId, $email, callable $callback) {
		global $_TABLES;
		$selector = self::createRandomString(16);
		$token = self::createRandomString(16);
		$tokenHashed = \password_hash($token, \PASSWORD_DEFAULT);
		$expires = \time() + 60 * 60 * 24;

		try {
			Database::getInstance()->conn->insert(
                $_TABLES['users_confirmations'],
				[
					'user_id' => (int) $userId,
					'email' => $email,
					'selector' => $selector,
					'token' => $tokenHashed,
					'expires' => $expires
				],
                [
                    Database::INTEGER,
                    Database::STRING,
                    Database::STRING,
                    Database::STRING,
					Database::INTEGER
				]
            );
		}
		catch (\Error $e) {
			throw new DatabaseError($e->getMessage());
		}

		if (\is_callable($callback)) {
			$callback($selector, $token);
		}
		else {
			throw new MissingCallbackError();
		}
	}

	/**
	 * Clears an existing directive that keeps the user logged in ("remember me")
	 *
	 * @param int $userId the ID of the user who shouldn't be kept signed in anymore
	 * @param string $selector (optional) the selector which the deletion should be restricted to
	 * @throws AuthError if an internal problem occurred (do *not* catch)
	 */
	protected function deleteRememberDirectiveForUserById($userId, $selector = null) {
        global $_TABLES;
		$whereMappings = [];

		if (isset($selector)) {
			$whereMappings['selector'] = (string) $selector;
		}

		$whereMappings['user'] = (int) $userId;

		try {
            Database::getInstance()->conn->delete(
                $_TABLES['users_remembered'],
                $whereMappings

            );
		}
		catch (\Throwable $e) {
			throw new DatabaseError($e->getMessage());
		}
	}

	/**
	 * Triggers a forced logout in all sessions that belong to the specified user
	 *
	 * @param int $userId the ID of the user to sign out
	 * @throws AuthError if an internal problem occurred (do *not* catch)
	 */
	protected function forceLogoutForUserById($userId) {
		global $_TABLES;

		$this->deleteRememberDirectiveForUserById($userId);

		try {
            Database::getInstance()->conn->update(
                $_TABLES['users'],
                array(
                    'force_logout' => 'force_logout + 1',
                    'uid' => $userId
                ),
                array(
                    Database::INTEGER,
                    Database::INTEGER
                )
            );
		}
		catch (\Throwable $e) {
			throw new DatabaseError($e->getMessage());
		}
		exit;
	}

	/**
	 * Builds a (qualified) full table name from an optional qualifier, an optional prefix, and the table name itself
	 *
	 * The optional qualifier may be a database name or a schema name, for example
	 *
	 * @param string $name the name of the table
	 * @return string[] the components of the (qualified) full name of the table
	 */
	protected function makeTableNameComponents($name) {
		$components = [];

		if (!empty($this->dbSchema)) {
			$components[] = $this->dbSchema;
		}

		if (!empty($name)) {
			if (!empty($this->dbTablePrefix)) {
				$components[] = $this->dbTablePrefix . $name;
			}
			else {
				$components[] = $name;
			}
		}

		return $components;
	}

	/**
	 * Builds a (qualified) full table name from an optional qualifier, an optional prefix, and the table name itself
	 *
	 * The optional qualifier may be a database name or a schema name, for example
	 *
	 * @param string $name the name of the table
	 * @return string the (qualified) full name of the table
	 */
	protected function makeTableName($name) {
		$components = $this->makeTableNameComponents($name);

		return \implode('.', $components);
	}

}