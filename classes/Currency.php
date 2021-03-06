<?php
/*
 * This file is part of wulacms.
 *
 * (c) Leo Ning <windywany@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace wallet\classes;

use wallet\classes\exception\WalletException;
use wulaphp\conf\ConfigurationLoader;

/**
 * Class Currency
 * @package wallet\classes
 * @property-read string $name     名称
 * @property-read string $id       ID
 * @property-read string $symbol   符号
 * @property-read int    $withdraw 是否可以提现
 * @property-read array  $types    收入类型
 * @property-read int    $decimals 精度
 * @property-read int    $scale    小数位数
 * @property-read int    $rate     与标准币兑换比例（一个标准币可以兑换多少个当前币）
 * @property-read array  $conf     配置信息
 */
class Currency implements \ArrayAccess {
	protected static $currencyConf;
	protected static $currencies = [];
	protected        $id;
	protected        $decimals;//精度
	protected        $realdec;//
	protected        $scale      = 6;//最大面值数位精度
	protected        $myConf;

	/**
	 * @param string $currency
	 *
	 * @return null|\wallet\classes\Currency
	 */
	public static function init(string $currency): ?Currency {
		if (isset(self::$currencies[ $currency ])) {
			return self::$currencies[ $currency ];
		}
		if (!self::$currencyConf) {
			self::$currencyConf = ConfigurationLoader::loadFromFile('wallet')->geta('currency');
		}
		if (!isset(self::$currencyConf[ $currency ])) {
			return null;
		}
		self::$currencies[ $currency ] = new Currency($currency, self::$currencyConf[ $currency ]);

		return self::$currencies[ $currency ];
	}

	/**
	 * 币种实例列表.
	 *
	 * @return \wallet\classes\Currency[]
	 */
	public static function currencies(): array {
		if (!self::$currencyConf) {
			self::$currencyConf = ConfigurationLoader::loadFromFile('wallet')->geta('currency');
		}

		foreach (self::$currencyConf as $cur => $cfg) {
			self::init($cur);
		}

		return self::$currencies;
	}

	/**
	 * Currency constructor.
	 *
	 * @param string $currency
	 * @param array  $cnf
	 */
	public function __construct(string $currency, array $cnf) {
		$this->id                 = $currency;
		$this->myConf             = array_merge([
			'name'     => $currency,
			'symbol'   => strtoupper($currency),
			'withdraw' => 0,
			'decimals' => 3,
			'scale'    => 6,
			'rate'     => 0,
			'types'    => []//收入类型
		], $cnf);
		$this->myConf['id']       = $currency;
		$this->decimals           = intval($this->myConf['decimals']);
		$this->scale              = intval($this->myConf['scale']);
		$this->realdec            = bcpow(10, $this->decimals);
		$this->myConf['decimals'] = $this->decimals;
	}

	/**
	 * 从最大面值单位转为最小面值单位.
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	public function toUint(string $value): ?string {
		if (!preg_match('/^(0|[1-9]\d*)(\.\d+)?$/', $value)) return null;

		return bcmul($value, $this->realdec);
	}

	/**
	 * 从最小面值单位转为最大面值单位.
	 *
	 * @param string   $value
	 * @param int|null $scale
	 *
	 * @return string
	 */
	public function fromUint(string $value, int $scale = null): string {
		if (!$this->realdec) return $value;
		if (!preg_match('/^-?([1-9]\d*)$/', $value)) return '0';
		$scale = $scale ?? $this->scale;
		if ($value < 0) {
			$amount = bcdiv(bcmul($value, '-1'), $this->realdec, $scale);
			if (strpos($amount, '.') > 0) {
				$amount = rtrim(rtrim($amount, '0'), '.');
			}
			$amount = '-' . $amount;
		} else {
			$amount = bcdiv($value, $this->realdec, $scale);
			if (strpos($amount, '.') > 0) {
				$amount = rtrim(rtrim($amount, '0'), '.');
			}
		}

		return $amount;
	}

	/**
	 * 检查收入类型是否可用.
	 *
	 * @param string $type
	 *
	 * @return null|array
	 */
	public function checkType(string $type): ?array {
		if (empty($type)) return null;
		$cfg = $this->myConf['types'][ $type ] ?? false;

		return $cfg && is_array($cfg) && isset($cfg['name']) ? array_merge(['withdraw' => 0], $cfg) : null;
	}

	/**
	 * 兑换金额.
	 *
	 * @param \wallet\classes\Currency $currency
	 * @param string                   $amount
	 *
	 * @return null|string
	 */
	public function exchangeAmount(Currency $currency, string $amount): ?string {
		$fromId = 'from' . $this->id;
		$froms  = $currency['types'];
		if ($this->myConf['rate'] > 0 && $currency->myConf['rate'] > 0 && isset($froms[ $fromId ])) {
			//先把本币除以兑换比例换成中间币X，然后把中间币X乘以目标币兑换比例得到目标币数量
			return bcdiv(bcmul($this->toUint($amount), $currency['rate']), $this->myConf['rate'], 0);
		}

		return null;
	}

	/**
	 * 充值（添加收入）
	 *
	 * @param \wallet\classes\Wallet $wallet
	 * @param string                 $amount
	 * @param string                 $type
	 * @param string                 $subjectId
	 *
	 * @return bool
	 * @throws \wallet\classes\exception\WalletException
	 */
	public function deposit(Wallet $wallet, string $amount, string $type, string $subjectId): bool {
		$typeCnf = $this->checkType($type);
		if (!$typeCnf) throw new WalletException('未知的收入类型:' . $type);;
		$subject = $typeCnf['subject'] ?? false;
		if (!$subject) throw new WalletException($type . '未配置subject');

		return $wallet->deposit($this, $amount, $type, $subject, $subjectId);
	}

	/**
	 * 支出(消费)
	 *
	 * @param \wallet\classes\Wallet $wallet
	 * @param string                 $amount
	 * @param string                 $subject
	 * @param string                 $subjectid
	 *
	 * @return bool
	 * @throws \wallet\classes\exception\WalletException
	 */
	public function outlay(Wallet $wallet, string $amount, string $subject, string $subjectid): bool {
		return $wallet->outlay($this, $amount, $subject, $subjectid);
	}

	/**
	 * @param \wallet\classes\Wallet   $wallet
	 * @param \wallet\classes\Currency $currencyTo
	 * @param string                   $amount
	 * @param float                    $discount
	 *
	 * @return null|string
	 * @throws \wallet\classes\exception\WalletException
	 * @throws \wallet\classes\exception\WalletLockedException
	 */
	public function exchange(Wallet $wallet, Currency $currencyTo, string $amount, float $discount = 1.0): ?string {
		return $wallet->exchange($this, $currencyTo, $amount, $discount);
	}

	public function __get(string $name) {
		if ($name == 'conf') {
			return $this->myConf;
		}

		return $this->myConf[ $name ] ?? null;
	}

	public function offsetExists($offset) {
		return isset($this->myConf[ $offset ]);
	}

	public function offsetGet($offset) {
		return $this->myConf[ $offset ] ?? null;
	}

	public function offsetSet($offset, $value) {
		//cannot set runtime
	}

	public function offsetUnset($offset) {
		//cannot unset runtime
	}

	public function __toString() {
		return $this->id;
	}
}