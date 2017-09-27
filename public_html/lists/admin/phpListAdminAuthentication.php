<?php

class phpListAdminAuthentication
{
    public $name = 'Default phpList Authentication';
    public $version = 0.1;
    public $authors = 'Michiel Dethmers';
    public $description = 'Provides authentication to phpList using the internal phpList administration database';

    /**
     * validateLogin, verify that the login credentials are correct.
     *
     * @param string $login    the login field
     * @param string $password the password
     *
     * @return array
     *               index 0 -> false if login failed, index of the administrator if successful
     *               index 1 -> error message when login fails
     *
     * eg
     *    return array(5,'OK'); // -> login successful for admin 5
     *    return array(0,'Incorrect login details'); // login failed
     */
    public function validateLogin($login, $password)
    {
        $query = sprintf('select password, disabled, id from %s where loginname = "%s"', $GLOBALS['tables']['admin'],
            sql_escape($login));
        $req = Sql_Query($query);
        $admindata = Sql_Fetch_Assoc($req);
        $passwordDB = $admindata['password'];

        if ($admindata['disabled']) {
            return array(0, s('your account has been disabled'));

        } elseif (//Password validation.
            password_verify($password, $passwordDB)
        ) {
            // Password matches stored hash, now check if it should be updated before returning
            if (password_needs_rehash($passwordDB, PHPLIST_PASSWORD)) {
                $this->setPassword($admindata['id'], $password);
            }
            // return sucess
            return array($admindata['id'], 'OK');

        } else {
            if (!empty($GLOBALS['admin_auth_module'])) {
                Error(s('Admin authentication has changed, please update your admin module'),
                    'https://resources.phplist.com/documentation/errors/adminauthchange');

                return;
            }

            // Backwards compatability for passwords created before phpList version TODO
            // remove after phpList version TODO
            $compathash = hash(HASH_ALGO, $password);
            if (!empty($passwordDB) && hash_equals($passwordDB, $compathash)) {
                $this->setPassword($admindata['id'], $password);
                // return sucess
                return array($admindata['id'], 'OK');
            }

            return array(0, s('incorrect password'));
        }

        if (!empty($GLOBALS['admin_auth_module'])) {
            Error(s('Admin authentication has changed, please update your admin module'),
                'https://resources.phplist.com/documentation/errors/adminauthchange');

            return;
        }

        return array(0, s('Login failed'));
    }
    /**
     * setPassword,
     *
     * Change the password for a given userid, password will be hashed
     * but it will NOT be checked for suitability
     *
     * @param int $adminid ID of the admin
     * @param string $password new password (plaintext)
     *
     * @return true on sucess, false on failure
     */
    public function setPassword($adminid, $password) {
        if (empty($password)) return false;

        $options = array();
        if ( array_key_exists(PHPLIST_PASSWORD_DEFAULT, $GLOBALS['phplist_password_parameters']) ) {
            // use user defined paramaters array from config
            $options = $GLOBALS['phplist_password_parameters'][PHPLIST_PASSWORD_DEFAULT];
        }

        $hashed_password = password_hash($password, PHPLIST_PASSWORD, $options);

        // "false or null may be returned" on error by password_compat, php core password_hash should return false on error
        if (is_string($hashed_password) === false) {
            logEvent('critical: password_hash failure');
            die('password_hash failure');
            return false;
        }

        $res = Sql_Query(
            sprintf('update %s set password = "%s", passwordchanged=now() where id = "%d"',
                $GLOBALS['tables']['admin'], $hashed_password, $adminid )
        );

        // return true if the SQL query did not error
        return $res === true;
    }

    public function getPassword($email)
    {
        $email = preg_replace("/[;,\"\']/", '', $email);
        $req = Sql_Query('select email,password,loginname from '.$GLOBALS['tables']['admin'].' where email = "'.sql_escape($email).'"');
        if (Sql_Affected_Rows()) {
            $row = Sql_Fetch_Row($req);

            return $row[1];
        }
    }

    /**
     * validateAccount, verify that the logged in admin is still valid.
     *
     * this allows verification that the admin still exists and is valid
     *
     * @param int $id the ID of the admin as provided by validateLogin
     *
     * @return array
     *               index 0 -> false if failed, true if successful
     *               index 1 -> error message when validation fails
     *
     * eg
     *    return array(1,'OK'); // -> admin valid
     *    return array(0,'No such account'); // admin failed
     */
    public function validateAccount($id)
    {
        /* can only do this after upgrade, which means
       * that the first login will always fail
      */

        $query = sprintf('select id, disabled,password from %s where id = %d', $GLOBALS['tables']['admin'], $id);
        $data = Sql_Fetch_Row_Query($query);
        if (!$data[0]) {
            return array(0, s('No such account'));
        } elseif ($data[1]) {
            return array(0, s('your account has been disabled'));
        }

        //# do this seperately from above, to avoid lock out when the DB hasn't been upgraded.
        //# so, ignore the error
        $query = sprintf('select privileges from %s where id = %d', $GLOBALS['tables']['admin'], $id);
        $req = Sql_Query($query);
        if ($req) {
            $data = Sql_Fetch_Row($req);
        } else {
            $data = array();
        }

        if (!empty($data[0])) {
            $_SESSION['privileges'] = unserialize($data[0]);
        }

        return array(1, 'OK');
    }

    /**
     * adminName.
     *
     * Name of the currently logged in administrator
     * Use for logging, eg "subscriber updated by XXXX"
     * and to display ownership of lists
     *
     * @param int $id ID of the admin
     *
     * @return string;
     */
    public function adminName($id)
    {
        $req = Sql_Fetch_Row_Query(sprintf('select loginname from %s where id = %d', $GLOBALS['tables']['admin'], $id));

        return $req[0] ? $req[0] : s('Nobody');
    }

    /**
     * adminEmail.
     *
     * Email address of the currently logged in administrator
     * used to potentially pre-fill the "From" field in a campaign
     *
     * @param int $id ID of the admin
     *
     * @return string;
     */
    public function adminEmail($id)
    {
        $req = Sql_Fetch_Row_Query(sprintf('select email from %s where id = %d', $GLOBALS['tables']['admin'], $id));

        return $req[0] ? $req[0] : '';
    }

    /**
     * adminIdForEmail.
     *
     * Return matching admin ID for an email address
     * used for verifying the admin email address on a Forgot Password request
     *
     * @param string $email email address
     *
     * @return ID if found or false if not;
     */
    public function adminIdForEmail($email)
    { //Obtain admin Id from a given email address.
        $req = Sql_Fetch_Row_Query(sprintf('select id from %s where email = "%s"', $GLOBALS['tables']['admin'],
            sql_escape($email)));

        return $req[0] ? $req[0] : '';
    }

    /**
     * isSuperUser.
     *
     * Return whether this admin is a super-admin or not
     *
     * @param int $id admin ID
     *
     * @return true if super-admin false if not
     */
    public function isSuperUser($id)
    {
        $req = Sql_Fetch_Row_Query(sprintf('select superuser from %s where id = %d', $GLOBALS['tables']['admin'], $id));

        return $req[0];
    }

    /**
     * listAdmins.
     *
     * Return array of admins in the system
     * Used in the list page to allow assigning ownership to lists
     *
     * @param none
     *
     * @return array of admins
     *               id => name
     */
    public function listAdmins()
    {
        $result = array();
        $req = Sql_Query("select id,loginname from {$GLOBALS['tables']['admin']} order by loginname");
        while ($row = Sql_Fetch_Array($req)) {
            $result[$row['id']] = $row['loginname'];
        }

        return $result;
    }
}
