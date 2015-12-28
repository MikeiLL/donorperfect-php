<?php namespace ThinkSayDo\DonorPerfectPHP;

class DonorPerfect
{
	// Setup

	protected static $apiUrl = 'https://www.donorperfect.net/prod/xmlrequest.asp';
	protected static $apiKey = '';

	// Raw

	public static function api($action='', $parseResponse = true)
	{
		$actionHash = md5($action);
		$apiResponse = null;

		$apiQuery = '?apikey=' . self::$apiKey . '&action=' . ((stripos($action, '&params') !== false) ? $action : rawurlencode(trim(str_ireplace(["\n","\t","  ","  )"], [" ",""," "," )"], $action))));

		if (strlen(self::$apiUrl . $apiQuery) > 2048)
		{
			throw new \Exception('DP API Call Exceeds Maximum Length');
		}

		$apiConnection = curl_init(self::$apiUrl . $apiQuery);

		curl_setopt($apiConnection, CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);
		curl_setopt($apiConnection, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($apiConnection, CURLOPT_TIMEOUT, 20);
		curl_setopt($apiConnection, CURLOPT_SSL_VERIFYPEER, FALSE);

		$apiResponse = '';
		
		while (empty($apiResponse) || trim($apiResponse) === 'The page cannot be displayed because an internal server error has occurred.')
		{
			$apiResponseLog = $apiResponse = curl_exec($apiConnection);
			sleep(1);
		}
		
		curl_close ($apiConnection);

        	// Fix values with invalid unescaped XML values
        	$apiResponse = preg_replace('|(?Umsi)(value=\'DATE:.*\\R*\')|', 'value=\'\'', $apiResponse);
		$apiResponse = json_decode(json_encode(simplexml_load_string($apiResponse)), true);

		if (is_array($apiResponse) && $parseResponse)
		{
			$apiResponse = self::parseApiResponse($apiResponse);
		}
	        elseif ( ! is_array($apiResponse))
	        {
	        	throw new \Exception('Error connecting to DonorPerfect.');
	        }

		return $apiResponse;
	}

	// Methods

	/*
	    @donor_id numeric
		@last_name NVarchar(100)
		@first_name NVarchar(50)
		@opt_line NVarchar(100)
		@address NVarchar(100)
		@city NVarchar(50)
		@state NVarchar(20)
		@zip NVarchar(50)
		@country NVarchar(50)
		@filter_id numeric
		@user_id NVarchar(20)
	 */
	public static function donorSearch($params=[])
	{
		$params = self::convertParams([
			'donor_id' => self::getInteger($params, 'donor_id'),
			'last_name' => self::getValue($params, 'last_name'),
			'first_name' => self::getValue($params, 'first_name'),
			'opt_line' => self::getValue($params, 'opt_line'),
			'address' => self::getValue($params, 'address'),
			'city' => self::getValue($params, 'city'),
			'state' => self::getValue($params, 'state'),
			'zip' => self::getValue($params, 'zip'),
			'country' => self::getValue($params, 'country'),
			'filter_id' => self::getInteger($params, 'filter_id'),
			'user_id' => self::getValue($params, 'user_id'),
		]);

		return self::api('dp_donorsearch&params=' . $params);
	}

	public static function listDonors($id=null)
	{
		$records = [];

		if (empty($records))
		{
			$pageSize = 500;
			$pageCount = 0;
			$pageStart = 1;
			$pageEnd = $pageSize;

			while ($pageSize !== null)
			{
				$response = self::api("
				SELECT
					*
				FROM (
					SELECT
						ROW_NUMBER() OVER(ORDER BY dp.first_name, dp.middle_name, dp.last_name ASC) AS row_number,
						/*ROW_NUMBER() OVER(ORDER BY dp.donor_id ASC) AS row_number,*/
						dp.donor_id,
						dp.first_name,
						dp.middle_name,
						dp.last_name,
						dp.email,
						dp.address,
						dp.address2,
						dp.city,
						dp.state,
						dp.zip,
						dp.country,
						dp.gift_total,
						dpudf.re_constituent_id
					FROM dp
					LEFT JOIN dpudf ON dpudf.donor_id = dp.donor_id
					WHERE
						(dp.nomail_reason != 'IA'
						AND dp.nomail_reason != 'DE')
						OR dp.nomail_reason IS NULL
					/*ORDER BY dp.first_name, dp.middle_name,dp.last_name ASC*/
				) AS tmp
				WHERE tmp.row_number BETWEEN {$pageStart} AND {$pageEnd}
			");

				if (is_array($response) && count($response) > 0)
				{
					$responseSize = count($response);

					for ($i=0;$i < $responseSize;$i++)
					{
						$records[] = $response[$i];
					}
				}
				else
				{
					$pageSize = null;
				}

				$pageCount++;
				$pageStart += $pageSize;
				$pageEnd += $pageSize;
			}
		}

		$recordsLength = count($records);

		for ($i=0;$i < $recordsLength;$i++)
		{
			if ($records[$i]->donor_id == $id)
			{
				return $records[$i];
			}
		}

		return $records;
	}

	/*
	    @donor_id numeric Enter 0 (zero) to create a new donor/constituent record or an existing donor_id
		@first_name NVarchar(100)
		@last_name NVarchar(150)
		@middle_name NVarchar(100)
		@suffix NVarchar(100)
		@title NVarchar(100)
		@salutation NVarchar(100)
		@prof_title NVarchar(100)
		@opt_line NVarchar(100)
		@address NVarchar(100)
		@address2 NVarchar(100)
		@city NVarchar(75)
		@state NVarchar(50)
		@zip NVarchar(50)
		@country NVarchar(50)
		@address_type NVarchar(30)
		@home_phone NVarchar(75)
		@business_phone NVarchar(75)
		@fax_phone NVarchar(75)
		@mobile_phone NVarchar(75)
		@email NVarchar(100)
		@org_rec NVarchar(1)
		@donor_type NVarchar(30)
		@nomail NVarchar(1)
		@nomail_reason NVarchar(30)
		@narrative text
		@user_id NVarchar(20)
	 */
	public static function saveDonor($params=[])
	{
		$params = self::convertParams([
			'donor_id' => self::getInteger($params, 'donor_id', 0),
			'first_name' => self::getValue($params, 'first_name'),
			'last_name' => self::getValue($params, 'last_name'),
			'middle_name' => self::getValue($params, 'middle_name'),
			'suffix' => self::getValue($params, 'suffix'),
			'title' => self::getValue($params, 'title'),
			'salutation' => self::getValue($params, 'salutation'),
			'prof_title' => self::getValue($params, 'prof_title'),
			'opt_line' => self::getValue($params, 'opt_line'),
			'address' => self::getValue($params, 'address'),
			'address2' => self::getValue($params, 'address2'),
			'city' => self::getValue($params, 'city'),
			'state' => self::getValue($params, 'state'),
			'zip' => self::getValue($params, 'zip'),
			'country' => self::getValue($params, 'country'),
			'address_type' => self::getValue($params, 'address_type'),
			'home_phone' => self::getValue($params, 'home_phone'),
			'business_phone' => self::getValue($params, 'business_phone'),
			'fax_phone' => self::getValue($params, 'fax_phone'),
			'mobile_phone' => self::getValue($params, 'mobile_phone'),
			'email' => self::getValue($params, 'email'),
			'org_rec' => self::getValue($params, 'org_rec'),
			'donor_type' => self::getValue($params, 'donor_type'),
			'nomail' => self::getValue($params, 'nomail'),
			'nomail_reason' => self::getValue($params, 'nomail_reason'),
			'narrative' => self::getValue($params, 'narrative'),
			'user_id' => self::getValue($params, 'user_id'),
		]);

		return self::api('dp_savedonor&params=' . $params);
	}

	/*
	    @donor_id numeric
	 */
	public static function gifts($params=[])
	{
		$params = self::convertParams([
			'donor_id' => self::getInteger($params, 'donor_id'),
		]);

		return self::api('dp_gifts&params=' . $params);
	}

	/*
	    @gift_id numeric Enter 0 in this field to create a new gift or the gift ID of an existing gift.
		@donor_id numeric
		@record_type NVarchar(30) ‘G’ for Gift, ‘P’ for Pledge
		@gift_date datetime
		@amount money
		@gl_code NVarchar(30)
		@solicit_code NVarchar(30)
		@sub_solicit_code NVarchar(30)
		@gift_type NVarchar(30)
		@split_gift NVarchar(1)
		@pledge_payment NVarchar(1)
		@reference NVarchar(25)
		@memory_honor NVarchar(30)
		@gfname NVarchar(50)
		@glname NVarchar(75)
		@fmv money
		@batch_no numeric
		@gift_narrative NVarchar(3000)
		@ty_letter_no NVarchar(30)
		@glink numeric
		@plink numeric
		@nocalc NVarchar(1)
		@receipt NVarchar(1)
		@old_amount money
		@user_id NVarchar(20)
		@campaign NVarchar(30) = NULL
		@membership_type NVarchar(30) = NULL
		@membership_level NVarchar(30) = NULL
		@membership_enr_date datetime = NULL
		@membership_exp_date datetime = NULL
		@membership_link_ID numeric = NULL
		@address_id numeric = NULL
	 */
	public static function saveGift($params=[])
	{
		$params = self::convertParams([
			'gift_id' => self::getInteger($params, 'gift_id', 0),
			'donor_id' => self::getInteger($params, 'donor_id'),
			'record_type' => self::getValue($params, 'record_type', 'G'), // P for Pledge, G for Gift
			'gift_date' => self::getValue($params, 'gift_date'),
			'amount' => self::getValue($params, 'amount'),
			'gl_code' => self::getValue($params, 'gl_code'),
			'solicit_code' => self::getValue($params, 'solicit_code'),
			'sub_solicit_code' => self::getValue($params, 'sub_solicit_code'),
			'gift_type' => self::getValue($params, 'gift_type'),
			'split_gift' => self::getValue($params, 'split_gift'),
			'pledge_payment' => self::getValue($params, 'pledge_payment'),
			'reference' => self::getValue($params, 'reference'),
			'memory_honor' => self::getValue($params, 'memory_honor'),
			'gfname' => self::getValue($params, 'gfname'),
			'glname' => self::getValue($params, 'glname'),
			'fmv' => self::getValue($params, 'fmv'),
			'batch_no' => self::getInteger($params, 'batch_no', 0),
			'gift_narrative' => self::getValue($params, 'gift_narrative'),
			'ty_letter_no' => self::getValue($params, 'ty_letter_no'),
			'glink' => self::getInteger($params, 'glink'),
			'plink' => self::getInteger($params, 'plink'),
			'nocalc' => self::getValue($params, 'nocalc'),
			'receipt' => self::getValue($params, 'receipt'),
			'old_amount' => self::getValue($params, 'old_amount'),
			'user_id' => self::getValue($params, 'user_id'),
			'campaign' => self::getValue($params, 'campaign'),
			'membership_type' => self::getValue($params, 'membership_type'),
			'membership_level' => self::getValue($params, 'membership_level'),
			'membership_enr_date' => self::getValue($params, 'membership_enr_date'),
			'membership_exp_date' => self::getValue($params, 'membership_exp_date'),
			'membership_link_id' => self::getInteger($params, 'membership_link_id'),
			'address_id' => self::getInteger($params, 'address_id'),
		]);

		return self::api('dp_savegift&params=' . $params);
	}

	/*
	    @gift_id numeric Enter 0 in this field to create a new pledge or the gift ID of an existing pledge.
		@donor_id numeric Enter the donor_id of the person for whom the pledge is being created/updated
		@gift_date datetime
		@start_date datetime
		@total money Enter either the total amount to be pledged (the sum of all the expected payment amounts) or enter 0 (zero) if the pledge amount is to be collected ad infinitum
		@bill money Enter the individual monthly/quarterly/annual billing amount
		@frequency NVarchar (30) Enter one of: M (monthly), Q (quarterly), S (semi-annually), A (annually)
		@reminder NVarchar (1) Sets the pledge reminder flag
		@gl_code NVarchar(30)
		@solicit_code NVarchar(30)
		@initial_payment NVarchar (1) Set to ‘’Y’ for intial payment, otherwise ‘N’
		@sub_solicit_code NVarchar(30)
		@writeoff_amount, money
		@writeoff_date datetime
		@user_id NNVarchar(20),
		@campaign NVarchar(30) Or NULL
		@membership_type NVarchar(30) Or NULL
		@membership_level NVarchar(30) Or NULL
		@membership_enr_date datetime Or NULL
		@membership_exp_date datetime Or NULL
		@membership_link_id numeric Or NULL
		@address_id numeric Or NULL
		@gift_narrative NVarchar(3000) Or NULL
		@ty_letter_no NVarchar(30) Or NULL
		@vault_id NVarchar(55) Or NULL
		@receipt_delivery_g NVarchar(30) ‘E’ for email, ‘B’ for both email and letter, ‘L’ for letter, ‘N’ for do not acknowledge or NULL
		@contact_id numeric Or NULL
	 */
	public static function savePledge($params=[])
	{
		$params = self::convertParams([
			'gift_id' => self::getInteger($params, 'gift_id', 0),
			'donor_id' => self::getInteger($params, 'donor_id'),
			'gift_date' => self::getValue($params, 'gift_date'),
			'start_date' => self::getValue($params, 'start_date'),
			'total' => self::getValue($params, 'total'),
			'bill' => self::getValue($params, 'bill'),
			'frequency' => self::getValue($params, 'frequency', 'M'),
			'reminder' => self::getValue($params, 'reminder', 'N'),
			'gl_code' => self::getValue($params, 'gl_code'),
			'solicit_code' => self::getValue($params, 'solicit_code'),
			'initial_payment' => self::getValue($params, 'initial_payment'),
			'sub_solicit_code' => self::getValue($params, 'sub_solicit_code'),
			'writeoff_amount' => self::getValue($params, 'writeoff_amount'),
			'writeoff_date' => self::getValue($params, 'writeoff_date'),
			'user_id' => self::getValue($params, 'user_id'),
			'campaign' => self::getValue($params, 'campaign'),
			'membership_type' => self::getValue($params, 'membership_type'),
			'membership_level' => self::getValue($params, 'membership_level'),
			'membership_enr_date' => self::getValue($params, 'membership_enr_date'),
			'membership_exp_date' => self::getValue($params, 'membership_exp_date'),
			'membership_link_id' => self::getValue($params, 'membership_link_id'),
			'address_id' => self::getInteger($params, 'address_id'),
			'gift_narrative' => self::getValue($params, 'gift_narrative'),
			'ty_letter_no' => self::getValue($params, 'ty_letter_no'),
			'vault_id' => self::getValue($params, 'vault_id'),
			'receipt_delivery_g' => self::getValue($params, 'receipt_delivery_g'),
			'contact_id' => self::getInteger($params, 'contact_id'),
		]);

		return self::api('dp_savepledge&params=' . $params);
	}

	/*
	    @other_id numeric Enter 0 to create a new record or the other_id record number of an existing dpotherinfo record
		@donor_id numeric Enter the donor_id for whom the record is to be created / updated.
		@other_date Date_time Format as date(‘m\/d\/Y,time())
		@comments NVarchar(500)
		@user_id NVarchar(20)
	 */
	public static function saveOtherInfo($params=[])
	{
		$params = self::convertParams([
			'other_id' => self::getInteger($params, 'other_id', 0),
			'donor_id' => self::getInteger($params, 'donor_id'),
			'other_date' => self::getValue($params, 'other_date'),
			'comments' => self::getValue($params, 'comments'),
			'user_id' => self::getValue($params, 'user_id'),
		]);

		return self::api('dp_saveotherinfo&params=' . $params);
	}

	/*
	    @matching_id numeric Specify either a donor_id value if updating a donor record, a gift_id value if updating a gift record or an other_id value if updating another info table value (see dp_saveotherinfo)
		@field_name NVarchar(20)
		@data_type NVarchar(1) C- Character, D-Date, N- Numeric
		@char_value NVarchar(2000) Null if not a Character field
		@date_value datetime Null if not a Date field
		@number_value numeric (18,4) Null if not a Number field
		@user_id NVarchar(20)
	 */
	public static function saveUdfXml($params=[])
	{
		$params = self::convertParams([
			'matching_id' => self::getInteger($params, 'matching_id'),
			'field_name' => self::getValue($params, 'field_name'),
			'data_type' => self::getValue($params, 'data_type'),
			'char_value' => self::getValue($params, 'char_value'),
			'date_value' => self::getValue($params, 'date_value'),
			'number_value' => self::getInteger($params, 'number_value'),
			'user_id' => self::getValue($params, 'user_id'),
		]);

		return self::api('dp_save_udf_xml&params=' . $params);
	}

	/*
	    @donor_id numeric Specify either a donor_id value if updating a donor record, a gift_id value if updating a gift record or an other_id value if updating another info table value (see dp_saveotherinfo)
		@flag varchar(20) Use the code value associated with the flag. For example, the ‘AL’, flag in this example had a description value of ‘Alumni’.
		@user_id varchar(20)
	 */
	public static function saveFlagXml($params=[])
	{
		$params = self::convertParams([
			'donor_id' => self::getInteger($params, 'donor_id'),
			'flag' => self::getValue($params, 'flag'),
			'user_id' => self::getValue($params, 'user_id'),
		]);

		return self::api('dp_saveflag_xml&params=' . $params);
	}

	/*
	    @donor_id numeric Specify the donor_id of the donor for whom the flags (all of them) are to be deleted
		@user_id varchar(20)
	 */
	public static function deleteFlagsXml($params=[])
	{
		$params = self::convertParams([
			'donor_id' => self::getInteger($params, 'donor_id'),
			'user_id' => self::getValue($params, 'user_id'),
		]);

		return self::api('dp_delflags_xml&params=' . $params);
	}

	/*
	    @contact_id numeric Enter 0 to create a new record or the other_id record number of an existing dpcontact record
		@donor_id Numeric Enter the Donor ID of the donor for whom the contact record is to be created or retrieved
		@activity_code NVarchar(30) CODE value for the Activity Code field. See DPO Settings > Code Maintenance > Activity Code / Contact Screen. The required values will be listed in the Code column of the resulting display.
		@mailing_code NVarchar(30) CODE value for Mailing Code field
		@by_whom NVarchar(30) CODE value for the By Whom/Contact Screen field in DPO Description value of selected code shows in the ‘Assigned To’ field of the contact record.
		@contact_date Datetime Contact / Entry Date field in DPO
		@due_date Datetime Due Date field in DPO
		@due_time NVarchar Time field in DPO
		@completed_date Datetime Completed Date field in DPO
		@comment NVarchar(3000) Contact Notes field in DPO
		@document_path NVarchar(200) Type a URL/File Path field in DPO
		@user_id NVarchar(20) Created by value – not shown in DPO user interface
	 */
	public static function saveContact($params=[])
	{
		$params = self::convertParams([
			'contact_id' => self::getInteger($params, 'contact_id', 0),
			'donor_id' => self::getInteger($params, 'donor_id'),
			'activity_code' => self::getValue($params, 'activity_code'),
			'mailing_code' => self::getValue($params, 'mailing_code'),
			'by_whom' => self::getValue($params, 'by_whom'),
			'contact_date' => self::getValue($params, 'contact_date'),
			'due_date' => self::getValue($params, 'due_date'),
			'due_time' => self::getValue($params, 'due_time'),
			'completed_date' => self::getValue($params, 'completed_date'),
			'comment' => self::getValue($params, 'comment'),
			'document_path' => self::getValue($params, 'document_path'),
			'user_id' => self::getValue($params, 'user_id'),
		]);

		return self::api('dp_savecontact&params=' . $params);
	}

	/*
	    @CustomerVaultID customer_vault_id NVarchar(55) Enter -0 to create a new Customer Vault ID record
		@donor_id int)
		@IsDefault is_default bit Bit Enter 1 if this is will be the default EFT payment method
		@AccountType account_type NVarchar(256) e.g. ‘Visa’
		@dpPaymentMethodTypeID dp_payment_method_type_id NVarchar(20) e.g.; ‘creditcard’
		@CardNumberLastFour card_number_last_four NVarchar(16) e.g.; ‘4xxxxxxxxxxx1111
		@CardExpirationDate card_expiration_date NVarchar(10) e.g.; ‘0810’
		@BankAccountNumberLastFour bank_account_number_last_four NVarchar(50)
		@NameOnAccount name_on_account NVarchar(256)
		@CreatedDate created_date datetime
		@ModifiedDate modified_date datetime
		@import_id int
		@created_by NVarchar(20)
		@modified_by NVarchar(20)
		@selected_currency NVarchar(3)
	 */
	public static function paymentMethodInsert($params=[])
	{
		$params = self::convertParams([
			'customer_vault_id' => self::getInteger($params, 'customer_vault_id', 0),
			'donor_id' => self::getInteger($params, 'donor_id'),
			'is_default' => self::getInteger($params, 'is_default', 0),
			'account_type' => self::getValue($params, 'account_type'),
			'dp_payment_method_type_id' => self::getValue($params, 'dp_payment_method_type_id'),
			'card_number_last_four' => self::getValue($params, 'card_number_last_four'),
			'card_expiration_date' => self::getValue($params, 'card_expiration_date'),
			'bank_account_number_last_four' => self::getValue($params, 'bank_account_number_last_four'),
			'name_on_account' => self::getValue($params, 'name_on_account'),
			'created_date' => self::getValue($params, 'created_date'),
			'modified_date' => self::getValue($params, 'modified_date'),
			'import_id' => self::getValue($params, 'import_id'),
			'created_by' => self::getValue($params, 'created_by'),
			'modified_by' => self::getValue($params, 'modified_by'),
			'selected_currency' => self::getValue($params, 'selected_currency'),
		]);

		return self::api('dp_paymentmethod_insert&params=' . $params);
	}

	public static function listFunds()
	{
		return self::api("
			SELECT
				funds.code,
				funds.description,
				funds.goal,
				funds.comments,
				funds.start_date,
				funds.end_date,
				funds.solicit_code2,
				funds.campaign,
				funds.created_date,
				category.code AS category_code,
				category.description AS category_description,
				category.goal AS category_goal,
				category.comments AS category_comments,
				category.start_date AS category_start_date,
				category.end_date AS category_end_date,
				category.created_date AS category_created_date,
				campaign.code AS campaign_code,
				campaign.description AS campaign_description,
				campaign.goal AS campaign_goal,
				campaign.comments AS campaign_comments,
				campaign.start_date AS campaign_start_date,
				campaign.end_date AS campaign_end_date,
				campaign.created_date AS campaign_created_date
			FROM dpcodes AS funds
			LEFT JOIN dpcodes AS category ON category.field_name = 'SOLICIT_CODE' AND category.code = funds.solicit_code2
			LEFT JOIN dpcodes AS campaign ON campaign.field_name = 'CAMPAIGN' AND campaign.code = funds.campaign
			WHERE
				funds.field_name = 'SUB_SOLICIT_CODE' AND
				funds.inactive != 'Y' AND
				(category.code IS NULL OR category.inactive != 'Y') AND
				(campaign.code IS NULL OR campaign.inactive != 'Y')
			ORDER BY funds.code ASC
		");
	}

	public static function getFund($code, $returnTotalGifts=false, $returnTotalGoalZero=true)
	{
		$code = (is_array($code)) ? $code : [$code];
		$funds = self::listFunds();

		$fund = array_filter($funds, function($fund) use ($code)
		{
			return (in_array($fund->code, $code));
		});

		if (count($code) === 1)
		{
			$fund = ( ! empty($fund)) ? array_shift($fund) : null;

			if ( ! empty($fund) && $returnTotalGifts && ($returnTotalGoalZero === true || $fund->goal > 0))
			{
				$totalGifts = self::api("SELECT SUM(amount) AS total FROM dpgift WHERE sub_solicit_code = '{$fund->code}'");

				if ( ! empty($totalGifts))
				{
					$fund->total = $totalGifts->total;
					$fund->remaining = number_format($fund->goal - $fund->total, 2, '.', '');
					if ($fund->remaining < 0) $fund->remaining = 0;

					if ($fund->goal > 0)
					{
						$fund->remainingPercentage = floor((1 - ($fund->total / $fund->goal)) * 100);
						if ($fund->remainingPercentage < 0) $fund->remainingPercentage = 0;
						elseif ($fund->remainingPercentage > 100) $fund->remainingPercentage = 100;
					}
					else
					{
						$fund->remainingPercentage = 0;
					}
				}
			}
		}

		return $fund;
	}

	public static function listGLs()
	{
		return self::api("
			SELECT
				gl.code,
				gl.description,
				gl.created_date,
				income_account.code AS income_account_code,
				income_account.description AS income_account_description,
				income_account.created_date AS income_account_created_date,
				cash_account.code AS cash_account_code,
				cash_account.description AS cash_account_description,
				cash_account.created_date AS cash_account_created_date
			FROM dpcodes AS gl
			LEFT JOIN dpcodes AS income_account ON income_account.field_name = 'ACCT_NUM' AND income_account.code = gl.acct_num
			LEFT JOIN dpcodes AS cash_account ON cash_account.field_name = 'CASHACT' AND cash_account.code = gl.cashact
			WHERE
				gl.field_name = 'GL_CODE' AND
				gl.inactive != 'Y' AND
				(income_account.code IS NULL OR income_account.inactive != 'Y') AND
				(cash_account.code IS NULL OR cash_account.inactive != 'Y')
			ORDER BY gl.code ASC
		");
	}

	public static function listClasses()
	{
		return self::api("
			SELECT
				class.code,
				class.description,
				class.created_date
			FROM dpcodes AS class
			WHERE
				class.field_name = 'CLASS' AND
				class.inactive != 'Y'
			ORDER BY class.code ASC
		");
	}

	public static function getClass($code)
	{
		$code = (is_array($code)) ? $code : [$code];
		$classes = self::listClasses();

		$class = array_filter($classes, function($class) use ($code)
		{
			return (in_array($class->code, $code));
		});

		return $class;
	}

	public static function getDonorData($id, $table='profile', $fields=null, $where=null, $orderBy=null)
	{
		$tableLookup = [
			'profile' => 'dp',
			'meta' => 'dpudf',
			'gifts' => 'dpgift',
			'pledges' => 'dpgift',
			'gifts_and_pledges' => 'dpgift',
			'flags' => 'dpflags',
			'links' => 'dplink',
			'addresses' => 'dpaddress',
			'contact_history' => 'dpcontact',
			'others' => 'dpotherinfo',
			'bio_options' => 'dpusermultivalues',
		];

		$giftsAndPledges = ($table === 'gifts_and_pledges');
		$wherePledge = ($table === 'pledges') ? 'AND record_type = \'P\'' : (($table === 'gifts') ? 'AND (record_type = \'G\' OR record_type = \'M\')' : '');

		$table = $tableLookup[$table];
		$join = '';
		$fields = ( ! empty($fields)) ? $fields : $table . '.*';
		$keyField = ($table === 'bio_options') ? 'matching_id' : 'donor_id';

		if ($giftsAndPledges)
		{
			$join = ' LEFT JOIN dpgiftudf ON dpgiftudf.gift_id = dpgift.gift_id';
			$fields .= ', dpgiftudf.class, dpgiftudf.eft_payment_type, dpgiftudf.eft_bank_name, dpgiftudf.eft_account, dpgiftudf.eft_routing, dpgiftudf.eft_expiration_year, dpgiftudf.eft_expiration_month, dpgiftudf.eft_recurring_id, dpgiftudf.anongift, dpgiftudf.gift_status';
		}

		$id = ( ! is_array($id)) ? $table . '.' . $keyField . ' = ' . $id : $table . '.' . $keyField . ' IN [' . implode(',', $id) . ']';
		if ( ! empty($where)) $where = 'AND '. $where;

		if ( ! empty($orderBy)) $orderBy = 'ORDER BY '. $orderBy;

		$response = self::api("
			SELECT
				{$fields}
			FROM {$table}
			{$join}
			WHERE
				{$id}
				{$where}
				{$wherePledge}
			{$orderBy}
		");

		return ($table === 'dp' || is_array($response)) ? $response : [$response];
	}

	public static function getGiftCustom($id, $fields=null, $where=null)
	{
		$table = 'dpgiftudf';
		$fields = ( ! empty($fields)) ? $fields : $table . '.*';

		$id = ( ! is_array($id)) ? $table . '.gift_id = ' . $id : $table . '.gift_id IN [' . implode(',', $id) . ']';
		if ( ! empty($where)) $where = 'AND '. $where;

		return self::api("
			SELECT
				{$fields}
			FROM {$table}
			WHERE
				{$id}
				{$where}
		");
	}

	public static function getContactHistoryCustom($id, $fields=null, $where=null)
	{
		$table = 'dpcontactudf';
		$fields = ( ! empty($fields)) ? $fields : $table . '.*';

		$id = ( ! is_array($id)) ? $table . '.contact_id = ' . $id : $table . '.contact_id IN [' . implode(',', $id) . ']';
		if ( ! empty($where)) $where = 'AND '. $where;

		return self::api("
			SELECT
				{$fields}
			FROM {$table}
			WHERE
				{$id}
				{$where}
		");
	}

	public static function getOtherCustom($id, $fields=null, $where=null)
	{
		$table = 'dpotherinfoudf';
		$fields = ( ! empty($fields)) ? $fields : $table . '.*';

		$id = ( ! is_array($id)) ? $table . '.other_id = ' . $id : $table . '.other_id IN [' . implode(',', $id) . ']';
		if ( ! empty($where)) $where = 'AND '. $where;

		return self::api("
			SELECT
				{$fields}
			FROM {$table}
			WHERE
				{$id}
				{$where}
		");
	}

	public static function getCodes($fieldName, $fields=null, $where=null)
	{
		$table = 'dpcodes';
		$fields = ( ! empty($fields)) ? $fields : $table . '.*';

		if ( ! empty($where)) $where = 'AND '. $where;

		return self::api("
			SELECT
				{$fields}
			FROM {$table}
			WHERE
				{$table}.field_name = '{$fieldName}'
				{$where}
		");
	}

	public static function listPledges($from, $to, $funds=null)
	{
		$funds = ( ! empty($funds) && ! is_array($funds)) ? $funds = [$funds] : null;
		$records = [];

		if (empty($records))
		{
			$pageSize = 500;
			$pageCount = 0;
			$pageStart = 1;
			$pageEnd = $pageSize;

			$dateRange = '(DATEPART(d, g.start_date) IN (';
			for ($i = $from; $i <= $to; $i++)
			{
				$dateRange .= $i . ',';
			}
			$dateRange = trim($dateRange, ',') . '))';

			$funds = ( ! empty($funds)) ? 'g.sub_solicit_code = \'' . implode('\' OR g.sub_solicit_code = \'', $funds) . '\'' : null;
			$fundFilter = ( ! empty($funds)) ? 'AND (' . $funds . ')' : '';

			while ($pageSize !== null)
			{
				$response = self::api("
					SELECT
						*
					FROM (
						SELECT
							ROW_NUMBER() OVER(ORDER BY d.first_name, d.middle_name, d.last_name ASC) AS row_number,
							d.donor_id,
							d.first_name,
							d.last_name,
							d.email,
							d.address,
							d.address2,
							d.city,
							d.state,
							d.zip,
							d.country,
							g.gift_id,
							g.gift_type,
							g.gift_date,
							g.start_date,
							g.bill AS amount,
							g.gl_code,
							g.solicit_code,
							g.sub_solicit_code,
							f.description AS sub_solicit_code_description,
							g.campaign,
							g.receipt,
							g.gift_narrative,
							dpgiftudf.class,
							c.description AS class_description,
							eft_payment_type,
							eft_bank_name,
							eft_account,
							eft_routing,
							eft_expiration_year,
							eft_expiration_month,
							eft_recurring_id,
							dpgiftudf.anongift
						FROM dpgift AS g
						LEFT JOIN dp AS d ON d.donor_id = g.donor_id
						LEFT JOIN dpgiftudf ON dpgiftudf.gift_id = g.gift_id
						LEFT JOIN dpcodes AS f ON f.code = g.sub_solicit_code AND f.field_name = 'SUB_SOLICITOR_CODE'
						LEFT JOIN dpcodes AS c ON c.code = dpgiftudf.class AND c.field_name = 'CLASS'
						WHERE
							g.record_type = 'P'
							AND g.frequency = 'M' /*M, ?, Q, S*/
							/*AND (g.writeoff_date IS NULL OR g.writeoff_date = '')*/
							AND dpgiftudf.gift_status = 'ACT'
							AND {$dateRange}
							{$fundFilter}
					) AS tmp
				WHERE tmp.row_number BETWEEN {$pageStart} AND {$pageEnd}
				");

				if (is_object($response) && ! empty($response->donor_id)) $response = [$response];

				if (is_array($response) && count($response) > 0)
				{
					$responseSize = count($response);

					for ($i=0;$i < $responseSize;$i++)
					{
						$records[] = $response[$i];
					}
				}
				else
				{
					$pageSize = null;
				}

				$pageCount++;
				$pageStart += $pageSize;
				$pageEnd += $pageSize;
			}
		}

		return $records;
	}

	public static function listGifts($from, $to, $onlyEFT=false, $funds=null)
	{
		$funds = ( ! empty($funds) && ! is_array($funds)) ? $funds = [$funds] : null;
		$records = [];

		if (empty($records))
		{
			$pageSize = 500;
			$pageCount = 0;
			$pageStart = 1;
			$pageEnd = $pageSize;

			$dateRange = ($from !== $to) ? '(g.gift_date >= \'' . $from . '\' AND g.gift_date <= \'' . $to . '\')' : '(g.gift_date = \'' . $from . '\')';

			$eftFilter = ($onlyEFT) ? 'AND (g.gift_type = \'EF\' OR u.eft_payment_type = \'EF\' OR g.gift_type = \'RGDD\') AND (u.eft_account IS NOT NULL AND u.eft_account != \'\') AND (u.eft_routing IS NOT NULL AND u.eft_routing != \'\')' : '';
			$funds = ( ! empty($funds)) ? 'g.sub_solicit_code = \'' . implode('\' OR g.sub_solicit_code = \'', $funds) . '\'' : null;
			$fundFilter = ( ! empty($funds)) ? 'AND (' . $funds . ')' : '';

			while ($pageSize !== null)
			{
				$response = self::api("
					SELECT
						*
					FROM (
						SELECT
							ROW_NUMBER() OVER(ORDER BY d.first_name, d.middle_name, d.last_name ASC) AS row_number,
							d.donor_id,
							d.first_name,
							d.last_name,
							d.home_phone,
							d.business_phone,
							d.mobile_phone,
							d.email,
							d.address,
							d.address2,
							d.city,
							d.state,
							d.zip,
							d.country,
							g.plink,
							g.gift_id,
							g.gift_type,
							g.gift_date,
							g.created_date,
							g.start_date,
							g.amount,
							g.gl_code,
							g.solicit_code,
							g.sub_solicit_code,
							f.description AS sub_solicit_code_description,
							g.campaign,
							g.receipt,
							g.gift_narrative,
							u.class,
							c.description AS class_description,
							u.eft_payment_type,
							u.eft_bank_name,
							u.eft_account,
							u.eft_routing,
							u.anongift,
							u.batch
						FROM dpgift AS g
						LEFT JOIN dp AS d ON d.donor_id = g.donor_id
						LEFT JOIN dpgiftudf AS u ON u.gift_id = g.gift_id
						LEFT JOIN dpcodes AS f ON f.code = g.sub_solicit_code AND f.field_name = 'SUB_SOLICITOR_CODE'
						LEFT JOIN dpcodes AS c ON c.code = u.class AND c.field_name = 'CLASS'
						WHERE g.record_type = 'G' AND {$dateRange} {$eftFilter} {$fundFilter}
					) AS tmp
				WHERE tmp.row_number BETWEEN {$pageStart} AND {$pageEnd}
				");
			
				if (is_object($response) && ! empty($response->donor_id)) $response = [$response];

				if (is_array($response) && count($response) > 0)
				{
					$responseSize = count($response);

					for ($i=0;$i < $responseSize;$i++)
					{
						$records[] = $response[$i];
					}
				}
				else
				{
					$pageSize = null;
				}

				$pageCount++;
				$pageStart += $pageSize;
				$pageEnd += $pageSize;
			}
		}

		return $records;
	}

	// Utilities

	protected static function convertParams($params)
	{
		return rawurlencode(implode(',', array_values($params)));
	}

	protected static function getInteger($params, $name, $default = 'null')
	{
		$value = (array_key_exists($name, $params)) ? $params[$name] : null;

		if (is_string($value)) $value = trim($value);

		try {
			$value = ( ! is_null($value)) ? (int) $value : $default;
		}
		catch (\Exception $e)
		{
			return $e;
		}

		return $value;
	}

	protected static function getValue($params, $name, $default = 'null')
	{
		$value = (array_key_exists($name, $params)) ? $params[$name] : null;

		if (is_string($value)) $value = trim($value);

		return ( ! empty($value)) ? "'" . str_replace(["'", '"', '%'], ["''", '', '%25'], $value) . "'" : $default;
	}

	protected static function parseApiResponse($response)
	{
		// Error
		if (array_key_exists('error', $response))
		{
			throw new \Exception($response['error']);
		}

		if (empty($response['record'])) return [];

		$records = $response['record'];
		$response = [];
		$isRow = false;

		foreach ($records as $i => $record)
		{
			// Happens with custom multi-record returns
			if (array_key_exists('field', $record))
			{
				$record = $record['field'];
				$isRow = true;
			}
			elseif ($isRow === true)
			{
				ddme('Error', $i, $record, $response);
			}

			// Happens with custom single-row record returns
			if (array_key_exists('@attributes', $record))
			{
				$record = [$record];
			}

			foreach ($record as $ii => $field)
			{
				$field = $field['@attributes'];

				if ($isRow && is_array($response) && ! array_key_exists($i, $response))
				{
					$response[$i] = (object) [];
				}
				elseif ( ! $isRow && is_array($response))
				{
					$response = (object) [];
				}

				// Record returned
				if ( ! empty($field['id']))
				{
					$field['id'] = strtolower($field['id']);
					$field['value'] = str_ireplace(['`'], ['\''], $field['value']);

					if ($isRow && is_array($response))
					{
						$response[$i]->{$field['id']} = $field['value'];
					}
					else
					{
						$response->{$field['id']} = $field['value'];
					}
				}

				// Item ID returned when saving or updating
				else
				{
					return (int) $field['value'];
				}
			}
		}

		return $response;
	}

	public static function escapeValue($value)
	{
		return ( ! empty($value)) ? str_replace(["'", '"', '%'], ["''", '', '%25'], $value) : $value;
	}

	public static function lookupGiftType($code)
	{
		$giftTypeLookup = [
			'EF' => 'eCheck',
			'VS' => 'VISA',
			'MC' => 'MasterCard',
			'AX' => 'American Express',
			'DI' => 'Discover',
		];

		foreach ($giftTypeLookup as $giftTypeCode => $giftTypeDescription)
		{
			if ($code == $giftTypeCode) return $giftTypeDescription;
			if ($code == $giftTypeDescription) return $giftTypeCode;
		}

		return null;
	}
}
