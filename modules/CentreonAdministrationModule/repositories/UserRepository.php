<?php
/*
 * Copyright 2005-2015 CENTREON
 * Centreon is developped by : Julien Mathis and Romain Le Merlus under
 * GPL Licence 2.0.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation ; either version 2 of the License.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program; if not, see <http://www.gnu.org/licenses>.
 *
 * Linking this program statically or dynamically with other modules is making a
 * combined work based on this program. Thus, the terms and conditions of the GNU
 * General Public License cover the whole combination.
 *
 * As a special exception, the copyright holders of this program give CENTREON
 * permission to link this program with independent modules to produce an executable,
 * regardless of the license terms of these independent modules, and to copy and
 * distribute the resulting executable under terms of CENTREON choice, provided that
 * CENTREON also meet, for each linked independent module, the terms  and conditions
 * of the license of that module. An independent module is a module which is not
 * derived from this program. If you modify this program, you may extend this
 * exception to your version of the program, but you are not obliged to do so. If you
 * do not wish to do so, delete this exception statement from your version.
 *
 * For more information : contact@centreon.com
 *
 */

namespace CentreonAdministration\Repository;

use CentreonAdministration\Models\User;
use CentreonAdministration\Models\Apitoken;
use CentreonAdministration\Models\Contact;
use CentreonAdministration\Models\ContactInfo;
use Centreon\Internal\Di;
use Centreon\Internal\Exception;
use CentreonSecurity\Internal\Sso;
use Centreon\Internal\Exception\Authentication\BadCredentialException;
use CentreonAdministration\Models\Relation\User\Timezone;

/**
 * @author Lionel Assepo <lassepo@centreon.com>
 * @package Centreon
 * @subpackage Repository
 */
class UserRepository extends Repository
{
    /**
     *
     * @var string
     */
    public static $tableName = 'cfg_users';
    
    /**
     *
     * @var string
     */
    public static $objectName = 'User';
    
    public static $objectClass = '\CentreonAdministration\Models\User';
    
    /**
     *
     * @var type 
     */
    public static $unicityFields = array(
        'fields' => array(
            'user' => 'cfg_users, user_id, login'
        ),
    );

    /**
     * 
     * @param array $givenParameters
     * @return integer
     */
    public static function create($givenParameters, $origin = "", $route = "", $validate = true, $validateMandatory = true)
    {
        if ($validate) {
            self::validateForm($givenParameters, $origin, $route, $validateMandatory);
        }
        
        $contactId = Contact::insert(array('description' => $givenParameters['login'] . " contact"));
        
        if (isset($givenParameters['password']) && $givenParameters['password']) {
            $givenParameters['password'] = static::generateHashedPassword($givenParameters);
        }
        
        $currentDate = date('Y-m-d H:i:s');
        $givenParameters['createdat'] = $currentDate;
        $givenParameters['updatedat'] = $currentDate;
        $givenParameters['contact_id'] = $contactId;
        
        $newId = parent::create($givenParameters);
        
        
        return $newId;
    }

    /**
     * 
     * @param string $givenParameters
     * @param string $login
     */
    public static function update($givenParameters, $origin = "form", $route = "", $validate = true, $validateMandatory = true)
    {
        if ($validate) {
            self::validateForm($givenParameters, $origin, $route, $validateMandatory);
        }

        /* Do not perform update if password is empty */
        if (isset($givenParameters['password']) && $givenParameters['password'] == '') {
            unset($givenParameters['password']);
        } elseif (isset($givenParameters['password'])) {
            $givenParameters['password'] = self::generateHashedPassword($givenParameters);
        }
        
        if (isset($givenParameters['login']) && !is_null($givenParameters['login']) && !isset($givenParameters['object_id'])) {
            $user = User::getIdByParameter('login', array($givenParameters['login']));
            if (is_array($user) && count($user) > 0) {
                $givenParameters['object_id'] = $user[0];
            }
        }
        
        $currentDate = date('Y-m-d H:i:s');
        $givenParameters['updatedat'] = $currentDate;
        
        parent::update($givenParameters, $origin, $route, $validate, $validateMandatory);
    }

    
    /** Check if last admin
     *
     * @param array $ids The ids of users to delete 
     */
    public static function isLastAdmin($ids){
        $di = Di::getDefault();
        $dbconn = $di->get('db_centreon');
        
        $query = "SELECT count(u.user_id) as admin_nbr from cfg_users u where u.is_admin = 1 and u.user_id not in (".implode(',',$ids).") ";
        $stmt = $dbconn->query($query);
        $stmt->execute();
        $res = $stmt->fetch();
        if($res['admin_nbr'] > 0){
            return false;
        }
        return true;
    }
    
    
    /**
     *
     * @param array $ids
     * @param object $currentUser
     */
    public static function delete($ids,$currentUser)
    {
        
        if(!self::isLastAdmin($ids)){
            if(!in_array($currentUser->getId(),$ids)){
                foreach ($ids as $id) {
                    $contact = User::getParameters($id, array('contact_id'));
                    if (isset($contact['contact_id'])) {
                        Contact::delete($contact['contact_id']);
                    }
                }
                parent::delete($ids);
            }else{
                throw new Exception('You can\'t delete yourself', 4404);
            }
        }else{
            throw new Exception('You can\'t delete the last admin', 4404);
        }
    }
    
    /**
     * 
     * @param array $givenParameters
     * @return string
     */
    private static function generateHashedPassword($givenParameters)
    {
        $saltPrefix = $givenParameters['login'] . $givenParameters['firstname'] . $givenParameters['lastname'];
        $salt = hash('sha256', uniqid(hash('sha256', $saltPrefix), true));
        $cost = 8000;
        $hashedPassword = hash_pbkdf2('sha256', $givenParameters['password'], $salt, $cost, 183);
        
        $finalPasswordForStorage = $salt . '::' . $cost . '::' . $hashedPassword;
        
        return $finalPasswordForStorage;
    }
    
    /**
     * 
     * @param string $login
     * @param string $password
     * @param string $autologin_key
     * @return type
     * @throws Exception
     */
    public static function checkUser($login, $password, $autologin_key = '')
    {
        $passwordCheck = true;
        
        $extraParams = array(
            'is_activated' => '1',
            'is_locked' => '0'
        );
        
        if (!empty($autologin_key)) {
            $extraParams['autologin_key'] = $autologin_key;
            $passwordCheck = false;
        }
        
        $userId = User::getIdByParameter(
            'login',
            array($login),
            $extraParams
        );
        
        if (!is_array($userId) || count($userId) == 0) {
            throw new Exception("User '" . $login . "' is not enable for reaching centreon", 4404);
        }
        
        $user = User::get($userId[0]);
        
        if ($passwordCheck) {
            if (!self::checkPassword($login, $password)) {
                throw new Exception("User '" . $login . "' doesn't match with password", 4403);
            }
        }
        
        return $user;
    }
    
    /**
     * 
     * @param string $login
     * @param string $password
     * @return boolean
     */
    public static function checkPassword($login, $password)
    {
        $loginResult = false;
        $userId = User::getIdByParameter('login', array($login));
        if (is_array($userId) && count($userId) > 0) {
            $user = User::getParameters($userId[0], array('password'));
            
            $explodedStoredPassword = explode('::', $user['password']);
            
            $hashedPassword = hash_pbkdf2(
                'sha256',
                $password,
                $explodedStoredPassword[0],
                $explodedStoredPassword[1],
                183
            );
            
            if ($explodedStoredPassword[2] === $hashedPassword) {
                $loginResult = true;
            }
        }
        return $loginResult;
    }

    /**
     * 
     * @param integer $contactId
     * @param string $object
     * @return string
     */
    public static function getNotificationInfos($contactId, $object)
    {
        // Initializing connection
        $di = Di::getDefault();
        $dbconn = $di->get('db_centreon');
        
        if ($object == 'host') {
            $ctp = 'timeperiod_tp_id';
        }
        $query = "SELECT tp_name, ".$object."_notification_options "
            . "FROM cfg_users, cfg_timeperiods "
            . "WHERE user_id='$contactId' "
            . "AND tp_id = $ctp" ;
        
        $stmt = $dbconn->query($query);
        $resultSet = $stmt->fetch();
        
        if ($resultSet === false) {
            $return = '';
        } else {
            $return = $resultSet['tp_name'].' ('.$resultSet[''.$object.'_notification_options'].')';
        }
        
        return $return;
    }
    
    /**
     * 
     * @param string $name
     * @param string $email
     * @return string
     */
    public static function getUserIcon($name, $email)
    {
        if ($email != "") {
            $name = "<img src='http://www.gravatar.com/avatar/".
                md5($email).
                "?rating=PG&size=16&default=' class='img-circle'>&nbsp;".
                $name;
        } else {
            $name = "<i class='fa fa-user'></i>&nbsp;".$name;
        }
        
        return $name;
    }

    /**
     *
     * @param string $userId
     * @return string
     */
    public static function getEmail($userId)
    {
        $contactEmail = array();
        $contactId = Contact::getParameters($userId, array('contact_id'));

        if (isset($contactId['contact_id'])) {
            $contactInfosId = ContactInfo::getIdByParameter('contact_id',$contactId['contact_id']);
            foreach ($contactInfosId as $id) {
                $contactInfos = ContactInfo::getParameters($id, array('info_key', 'info_value'));
                if ($contactInfos['info_key'] === 'email') {
                    $contactEmail[] = $contactInfos['info_value'];
                }
            }
        }

        return $contactEmail;
    }

    /**
     * 
     * @param integer $user_id
     * @param string $type
     * @return string
     */
    public static function getNotificationCommand($user_id, $type)
    {
        $di = Di::getDefault();

        /* Get Database Connexion */
        $dbconn = $di->get('db_centreon');
        
        if ($type != "host" && $type != "service") {
            return "";
        }

        /* Launch Request */
        $query = "SELECT command_name FROM cfg_users_".$type."commands_relations, cfg_commands "
            . "WHERE user_id = $user_id AND command_command_id = command_id";
        $stmt = $dbconn->prepare($query);
        $stmt->execute();
        $cmd = "";
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if ($cmd != "") {
                $cmd .= ",";
            }
            $cmd .= $row["command_name"];
        }
        return $cmd;
    }
    
    /**
     * 
     * @param string $login
     * @param string $password
     * @return string
     * @throws BadCredentialException
     */
    public static function getTokenForApi($login, $password)
    {
        $token = "";
        
        try {
            $connectedUser = new Sso($login, $password, 0);
            $token = hash_pbkdf2('sha256', $login . $password, hash('sha256', uniqid($login, true)), 8000, 200);
            Apitoken::insert(array('user_id' => $connectedUser->userInfos['user_id'], 'value' => $token));
        } catch (Exception $e) {
            if ($e->getCode() === 4403) {
                throw new BadCredentialException($e->getMessage(), $e->getCode(), $e);
            }
        }
        
        return $token;
    }
    
    /**
     * 
     * @param string $token
     * @return boolean
     */
    public static function checkApiToken($token)
    {
        $tokenOk = false;
        $storedToken = Apitoken::getIdByParameter('value', array($token));
        if (is_array($storedToken) && (count($storedToken) == 1)) {
            $tokenOk = true;
        }
        return $tokenOk;
    }
    /**
     * 
     * @param array $givenParameters
     */
    public static function settimezone($givenParameters)
    {
        Timezone::insert($givenParameters['user_id'], $givenParameters['timezone_id']);
    }
    
    /**
     * 
     * @param array $givenParameters
     */
    public static function deletetimezone($givenParameters)
    {
        Timezone::delete($givenParameters['user_id'], $givenParameters['timezone_id']);
    }
    
    
}
