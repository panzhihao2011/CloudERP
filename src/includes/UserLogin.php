<?php

/*  Performs login checks and $_SESSION initialisation */
/* $Id: UserLogin.php 7067 2015-01-05 03:41:43Z rchacon $*/

define('UL_OK',  0);		/* User verified, session initialised */
define('UL_NOTVALID', 1);	/* User/password do not agree */
define('UL_BLOCKED', 2);	/* Account locked, too many failed logins */
define('UL_CONFIGERR', 3);	/* Configuration error in webERP or server */
define('UL_SHOWLOGIN', 4);
define('UL_MAINTENANCE', 5);
define('UL_PORTAL_NOTVALID', 6);    /* Portal认证未通过 */
define('UL_PORTAL_DISABLE', 7);    /* Portal认证未启用 */
define('UL_PORTAL_NOTUSER', 8);    /* 非Portal用户 */

/*	UserLogin
 *  Function to validate user name,  perform validity checks and initialise
 *  $_SESSION data.
 *  Returns:
 *	See define() statements above.
 */

/**
 * 用户登录认证和Session初始化总入口函数
 * 
 * 一、App登录：
 * 1）普通用户：使用用户名、密码，按系统内置方式验证。
 * 2）Portal用户：使用用户名、密码，调用Portal密码认证接口验证。
 * 
 * 二、Portal单点登录：
 * 按照用户名检查是否为Portal用户，不验证密码
 * 
 * @param string $Name          用户名
 * @param string $Password      密码，Portal单点登录时为空
 * @param string $SysAdminEmail 管理员邮箱地址。用户锁定后自动送发送提醒邮件。
 * @param string $db            数据库
 * @param string $LoginFrom     app/portal，分别表示App登录、Portal单点登录
 * @return string
 */
function userLogin($Name, $Password, $SysAdminEmail = '', $db, $LoginFrom)
{
	global $debug;
	global $PathPrefix;

	if (!isset($_SESSION['AccessLevel']) OR $_SESSION['AccessLevel'] == '' OR
		(isset($Name) AND $Name != ''))
	{
	   /* if not logged in */
		$_SESSION['AccessLevel'] = '';
		$_SESSION['CustomerID'] = '';
		$_SESSION['UserBranch'] = '';
		$_SESSION['SalesmanLogin'] = '';
		$_SESSION['Module'] = '';
		$_SESSION['PageSize'] = '';
		$_SESSION['UserStockLocation'] = '';
		$_SESSION['AttemptsCounter']++;

		// Show login screen
		if (!isset($Name) or $Name == '') {
			$_SESSION['DatabaseName'] = '';
		    $_SESSION['CompanyName'] = '';
			return  UL_SHOWLOGIN;
		}
		/* The SQL to get the user info must use the * syntax because the field name could change between versions if the fields are specifed directly then the sql fails and the db upgrade will fail */
		$sql = "SELECT *
				FROM www_users
				WHERE www_users.userid='" . $Name . "'";

		$ErrMsg = _('Could not retrieve user details on login because');
		$debug =1;
        $PasswordVerified = false;
        $Auth_Result = DB_query($sql,$ErrMsg);
        
		if (DB_num_rows($Auth_Result) > 0)
		{
			$myrow = DB_fetch_array($Auth_Result);
            global $SysAdmin;
			global $IsPortalEnable;
				
			if ($LoginFrom == "portal")
			{
			    /**
			     * Portal单点登录
			     */

			    /**
			     * 验证启用Portal认证模式是否启用
			     */
			    if (! isset($IsPortalEnable) OR ($IsPortalEnable != true))
			    {
			        return UL_PORTAL_DISABLE;
			    }

			    /**
			     * 是否为Portal用户
			     */
			    if (empty($myrow["portalid"]))
			    {
			        return UL_PORTAL_NOTUSER;
			    }

			    $PasswordVerified = true;
			}
			elseif ($LoginFrom == "app")
			{
			    /**
			     * App登录
			     */
    			if (VerifyPass($Password, $myrow['password']))
    			{
    			    /**
    			     * 普通用户认证
    			     */
    				$PasswordVerified = true;
    			}
				elseif ($IsPortalEnable
				    AND ($Name != $SysAdmin)
				    AND (! empty($myrow["portalid"])))
			    {
			        /**
			         * Portal用户认证
			         * 管理员不参加认证
			         */
		            if (Portal_PasswordValidate($Name, $Password))
		            {
		                $PasswordVerified = true;
		            }
		            else
		            {
		                return UL_PORTAL_NOTVALID;
		            }
			    }
    			elseif (isset($GLOBALS['CryptFunction']))
    			{
    				/*if the password stored in the DB was compiled the old way,
    				 * the previous comparison will fail,
    				 * try again with the old hashing algorithm,
    				 * then re-hash the password using the new algorithm.
    				 * The next version should not have $CryptFunction any more for new installs.
    				 */
    				switch ($GLOBALS['CryptFunction'])
    				{
    					case 'sha1':
    						if ($myrow['password'] == sha1($Password))
    						{
    							$PasswordVerified = true;
    						}
    						break;
    					case 'md5':
    						if ($myrow['password'] == md5($Password))
    						{
    							$PasswordVerified = true;
    						}
    						break;
    					default:
    						if ($myrow['password'] == $Password)
    						{
    							$PasswordVerified = true;
    						}
    				}
    
    				if ($PasswordVerified)
    				{
    					$sql = "UPDATE www_users SET password = '" . CryptPass($Password) . "'"
    							. " WHERE userid = '" . $Name . "';";
    					DB_query($sql);
    				}
    			}
			}
		}

		// Populate session variables with data base results
		if ($PasswordVerified)
		{
			if ($myrow['blocked']==1){
			//the account is blocked
				return  UL_BLOCKED;
			}
			/*reset the attempts counter on successful login */
			$_SESSION['UserID'] = $myrow['userid'];
			$_SESSION['AttemptsCounter'] = 0;
			$_SESSION['AccessLevel'] = $myrow['fullaccess'];
			$_SESSION['CustomerID'] = $myrow['customerid'];
			$_SESSION['UserBranch'] = $myrow['branchcode'];
			$_SESSION['DefaultPageSize'] = $myrow['pagesize'];
			$_SESSION['UserStockLocation'] = $myrow['defaultlocation'];
			$_SESSION['UserEmail'] = $myrow['email'];
			$_SESSION['ModulesEnabled'] = explode(",", $myrow['modulesallowed']);
			$_SESSION['UsersRealName'] = $myrow['realname'];
			$_SESSION['Theme'] = $myrow['theme'];
			$_SESSION['Language'] = $myrow['language'];
			$_SESSION['SalesmanLogin'] = $myrow['salesman'];
			$_SESSION['CanCreateTender'] = $myrow['cancreatetender'];
			$_SESSION['AllowedDepartment'] = $myrow['department'];
			$_SESSION['ShowDashboard'] = $myrow['showdashboard'];

			if (isset($myrow['pdflanguage'])) {
				$_SESSION['PDFLanguage'] = $myrow['pdflanguage'];
			} else {
				$_SESSION['PDFLanguage'] = '0'; //default to latin western languages
			}

			if ($myrow['displayrecordsmax'] > 0) {
				$_SESSION['DisplayRecordsMax'] = $myrow['displayrecordsmax'];
			} else {
				$_SESSION['DisplayRecordsMax'] = $_SESSION['DefaultDisplayRecordsMax'];  // default comes from config.php
			}

			$sql = "UPDATE www_users SET lastvisitdate='". date('Y-m-d H:i:s') ."'
							WHERE www_users.userid='" . $Name . "'";
			$Auth_Result = DB_query($sql);
			/*get the security tokens that the user has access to */
			$sql = "SELECT tokenid
					FROM securitygroups
					WHERE secroleid =  '" . $_SESSION['AccessLevel'] . "'";
			$Sec_Result = DB_query($sql);
			$_SESSION['AllowedPageSecurityTokens'] = array();
			if (DB_num_rows($Sec_Result)==0){
				return  UL_CONFIGERR;
			} else {
				$i=0;
				$UserIsSysAdmin = FALSE;
				while ($myrow = DB_fetch_row($Sec_Result)){
					if ($myrow[0] == 15){
						$UserIsSysAdmin = TRUE;
					}
					$_SESSION['AllowedPageSecurityTokens'][$i] = $myrow[0];
					$i++;
				}
			}


			/*User is logged in so get configuration parameters  - save in session*/
			include($PathPrefix . 'includes/GetConfig.php');


			if(isset($_SESSION['DB_Maintenance'])){
				if ($_SESSION['DB_Maintenance']>0)  { //run the DB maintenance script
					if (DateDiff(Date($_SESSION['DefaultDateFormat']),
							ConvertSQLDate($_SESSION['DB_Maintenance_LastRun'])
							,'d')	>= 	$_SESSION['DB_Maintenance']){

						/*Do the DB maintenance routing for the DB_type selected */
						DB_Maintenance();
						$_SESSION['DB_Maintenance_LastRun'] = Date('Y-m-d');

						/* Audit trail purge only runs if DB_Maintenance is enabled */
						if (isset($_SESSION['MonthsAuditTrail'])){
							 $sql = "DELETE FROM audittrail
									WHERE  transactiondate <= '" . Date('Y-m-d', mktime(0,0,0, Date('m')-$_SESSION['MonthsAuditTrail'])) . "'";
							$ErrMsg = _('There was a problem deleting expired audit-trail history');
							$result = DB_query($sql);
						}
					}
				}
			}

			/*Check to see if currency rates need to be updated */
			if (isset($_SESSION['UpdateCurrencyRatesDaily'])){
				if ($_SESSION['UpdateCurrencyRatesDaily']!=0)  {
					/* Only run the update to currency rates if today is after the last update i.e. only runs once a day */
					if (DateDiff(Date($_SESSION['DefaultDateFormat']),
						ConvertSQLDate($_SESSION['UpdateCurrencyRatesDaily']),'d')> 0){

						if ($_SESSION['ExchangeRateFeed']=='ECB') {
							$CurrencyRates = GetECBCurrencyRates(); // gets rates from ECB see includes/MiscFunctions.php
							/*Loop around the defined currencies and get the rate from ECB */
							if ($CurrencyRates!=false) {
								$CurrenciesResult = DB_query("SELECT currabrev FROM currencies");
								while ($CurrencyRow = DB_fetch_row($CurrenciesResult)){
									if ($CurrencyRow[0]!=$_SESSION['CompanyRecord']['currencydefault']){

										$UpdateCurrRateResult = DB_query("UPDATE currencies SET rate='" . GetCurrencyRate($CurrencyRow[0],$CurrencyRates) . "'
																			WHERE currabrev='" . $CurrencyRow[0] . "'",$db);
									}
								}
							}
						} else {
							$CurrenciesResult = DB_query("SELECT currabrev FROM currencies");
							while ($CurrencyRow = DB_fetch_row($CurrenciesResult)){
								if ($CurrencyRow[0]!=$_SESSION['CompanyRecord']['currencydefault']){
									$UpdateCurrRateResult = DB_query("UPDATE currencies SET rate='" . google_currency_rate($CurrencyRow[0]) . "'
																		WHERE currabrev='" . $CurrencyRow[0] . "'",$db);
								}
							}
						}
						$_SESSION['UpdateCurrencyRatesDaily'] = Date('Y-m-d');
						$UpdateConfigResult = DB_query("UPDATE config SET confvalue = '" . Date('Y-m-d') . "' WHERE confname='UpdateCurrencyRatesDaily'");
					}
				}
			}

			/*
			 * 更新登录信息到cookies
			 * cookies超时时间暂定为30天
			 */
			setcookie('login_name', $Name, time() + 3600 * 24 * 30);
			$is_remember = $_POST['Is_Remember'][0];
			if ($is_remember == 1)
			{
			    setcookie('login_password', $Password, time() + 3600 * 24 * 30);
			    setcookie('is_remember', $is_remember, time() + 3600 * 24 * 30);
			}
			else
			{
			    /* 清除cookies并过期 */
			    setcookie('login_password', "", time() - 3600);
			    setcookie('is_remember', "", time() - 3600);
            }

			/* Set the logo if not yet set.
			 * will be done only once per session and each time
			 * we are not in session (i.e. before login)
			 */
			if (empty($_SESSION['LogoFile'])) {
				/* find a logo in companies/CompanyDir */
				if (file_exists($PathPrefix . 'companies/' . $_SESSION['DatabaseName'] . '/logo.png')) {
					$_SESSION['LogoFile'] = 'companies/' .  $_SESSION['DatabaseName'] . '/logo.png';
				} elseif (file_exists($PathPrefix . 'companies/' . $_SESSION['DatabaseName'] . '/logo.jpg')) {
					$_SESSION['LogoFile'] = 'companies/' .  $_SESSION['DatabaseName'] . '/logo.jpg';
				}
			}


			if(!isset($_SESSION['DB_Maintenance'])){
				return  UL_CONFIGERR;
			} else {

				if ($_SESSION['DB_Maintenance']==-1 AND !in_array(15, $_SESSION['AllowedPageSecurityTokens'])){
					// the configuration setting has been set to -1 ==> Allow SysAdmin Access Only
					// the user is NOT a SysAdmin
					return  UL_MAINTENANCE;
				}
			}
		}
		else
		{     // Incorrect password
			// 5 login attempts, show failed login screen
			if (!isset($_SESSION['AttemptsCounter'])) {
				$_SESSION['AttemptsCounter'] = 0;
			} elseif ($_SESSION['AttemptsCounter'] >= 5 AND isset($Name)) {
				/*User blocked from future accesses until sysadmin releases */
				$sql = "UPDATE www_users
							SET blocked=1
							WHERE www_users.userid='" . $Name . "'";
				$Auth_Result = DB_query($sql);

				if ($SysAdminEmail != ''){
					$EmailSubject = _('User access blocked'). ' ' . $Name ;
					$EmailText =  _('User ID') . ' ' . $Name . ' - ' . $Password . ' - ' . _('has been blocked access at') . ' ' .
								Date('Y-m-d H:i:s') . ' ' . _('from IP') . ' ' . $_SERVER["REMOTE_ADDR"] . ' ' . _('due to too many failed attempts.');
					if($_SESSION['SmtpSetting']==0){
							mail($SysAdminEmail,$EmailSubject,$EmailText);

					} else{
							include('includes/htmlMimeMail.php');
							$mail = new htmlMimeMail();
							$mail->setSubject($EmailSubject);
							$mail->setText($EmailText);
							$result = SendmailBySmtp($mail,array($SysAdminEmail));
					}

				}

				return  UL_BLOCKED;
			}
			return  UL_NOTVALID;
		}
	}		// End of userid/password check
	// Run with debugging messages for the system administrator(s) but not anyone else

	return   UL_OK;		    /* All is well */
}

?>
