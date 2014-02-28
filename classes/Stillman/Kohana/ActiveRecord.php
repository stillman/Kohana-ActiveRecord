<?php

namespace Stillman\Kohana;

use Arr;
use DB;

class ActiveRecord
{
	// Relation types

	const HAS_ONE    = 'HAS ONE';
	const BELONGS_TO = 'BELONGS TO';

	// Join types

	const LEFT_JOIN  = 'LEFT JOIN';
	const RIGHT_JOIN = 'RIGHT JOIN';
	const INNER_JOIN = 'INNER JOIN';

	// Set to true to enable profiling
	public static $profiling = false;

	public static $fields = [];

	public static $relations = [];

	public static $primary_key = 'Id';

	protected $_criteria = [];

	protected $_errors = [];

	public static function model($class = null)
	{
		if ($class === null)
			return new static;

		return new $class;
	}

	/**
	 * Create an object or array of objects of the specified class
	 *
	 * @param  array   $array     Objects data
	 * @param  bool    $multiple  Create array of objects or just one object?
	 * @param  string  $class     Optional class name of created objects
	 *
	 * @return mixed   array of object if $multiple is true, object of the specified class otherwise
	 */
	public static function create(array $array, $multiple = false, $class = null)
	{
		if ($class === null)
		{
			$class = static::class;
		}

		if ($multiple)
		{
			$objects = [];

			foreach ($array as $key => $element)
			{
				$objects[$key] = $class::create($element);
			}

			return $objects;
		}

		$object = new $class;

		foreach ($class::$fields as $field => $val)
		{
			$object->$field = isset($array[$field]) ? $array[$field] : $val['default'];
		}

		$object->afterLoad();
		return $object;
	}

	/**
	 * Create object (including compound objects) from array of data
	 *
	 * @param   array  $values  Data array
	 * @return  \Stillman\Kohana\ActiveRecord
	 */
	protected static function _createObject(array $values)
	{
		foreach ($values as $key => $value)
		{
			if (strpos($key, ':') === false)
			{
				$objects['__self__'][$key] = $value;
			}
			else
			{
				list($related, $name) = explode(':', $key, 2);
				$objects[$related][$name] = $value;
			}
		}

		$object = static::create($objects['__self__'], false);
		unset($objects['__self__']);

		foreach ($objects as $name => $values)
		{
			if (strpos($name, '.') === false)
			{
				$related_class_name = static::$relations[$name]['class'];
				$object->$name = $related_class_name::create($values, false);
			}
			else
			{
				// Nested objects (ex., user.profile)

				$parts = explode('.', $name);
				$class = $object->{$parts[0]};
				unset($parts[0]);
				$last = $parts[count($parts)];

				foreach ($parts as $part)
				{
					$class->$part = 1;
				}

				$cls = $class::$relations[$last]['class'];
				$class->$last = $cls::create($values);
			}
		}

		return $object;
	}

	public function __construct()
	{
		$this->resetCriteria();
	}

	public function orderBy($order)
	{
		$this->_criteria['ORDER_BY'] = $order;
		return $this;
	}

	public function limit($limit, $offset = 0)
	{
		$this->_criteria['LIMIT'] = (int) $limit;
		$this->_criteria['OFFSET'] = (int) $offset;
		return $this;
	}

	public function isNew()
	{
		return empty($this->{static::$primary_key});
	}

	public function resetCriteria()
	{
		$this->_criteria = [
			'SELECT' => ['t.*'],
			'FROM' => static::$table_name." t",
			'WHERE' => [],
		];
	}

	public function addCriteria(array $criteria)
	{
		$this->_criteria = Arr::merge($this->_criteria, $criteria);
		return $this;
	}

	public function addCondition($condition, array $params = [])
	{
		if ( ! is_array($condition))
		{
			$condition = [$condition];
		}

		$this->addCriteria(['WHERE' => $condition, 'params' => $params]);
		return $this;
	}

	public function with($alias, $join_type = null)
	{
		if ($join_type === null)
		{
			$join_type = static::LEFT_JOIN;
		}

		// Dotted relations, ex. user.profile
		$parts = explode('.', $alias);

		if (count($parts) === 1)
		{
			$class1 = static::class;
			$relation = $class1::$relations[$alias];
			$class2 = $relation['class'];
			$table1alias = 't';
			$_ = $alias;
		}
		else
		{
			$class2 = static::$relations[$parts[0]]['class'];
			unset($parts[0]);

			if ($parts)
			{
				foreach ($parts as $c => $_part)
				{
					$class1 = $class2;
					$class2 = $class2::$relations[$_part]['class'];
				}

				$table1alias = substr($alias, 0, strrpos($alias, '.'));
				$_ = substr($alias, strrpos($alias, '.') + 1);
			}
		}

		$table1 = $class1::$table_name;
		$table2 = $class2::$table_name;
		$table2alias = $alias;

		$relation_type = $class1::$relations[$_]['relation_type'];

		switch ($relation_type)
		{
			case static::HAS_ONE:
				$criteria['JOIN'][$alias] = "$join_type ".$class2::$table_name." AS `$table2alias` ON `$table2alias`.`".$class1::$relations[$_]['fk']."` = `$table1alias`.`".$class1::$primary_key.'`';
				break;

			case static::BELONGS_TO:
				$criteria['JOIN'][$alias] = "$join_type ".$class2::$table_name." AS `$table2alias` ON `$table1alias`.`".$class1::$relations[$_]['fk']."` = `$table2alias`.`".$class2::$primary_key.'`';
				break;

			default:
				throw new ActiveRecord\Exception('Relation type is not supported yet');
		}

		foreach (array_keys($class2::$fields) as $field)
		{
			$criteria['SELECT'][] = "`$alias`.`$field` AS `$alias:$field`";
		}

		$this->addCriteria($criteria);
		return $this;
	}

	/**
	 * Get primary key value
	 */
	public function pk()
	{
		return $this->{static::$primary_key};
	}

	public function getCriteria()
	{
		return $this->_criteria;
	}

	public function find()
	{
		return $this->_find(false);
	}

	public function findAll($key = null)
	{
		return $this->_find(true, $key);
	}

	public function findBy($field, $value, array $criteria = [], $multiple = false)
	{
		$param_name = uniqid(':', true);
		$this->_criteria['WHERE'] = ["$field = $param_name"];
		$this->_criteria['params'][$param_name] = $value;

		if ($criteria)
		{
			$this->addCriteria($criteria);
		}

		return $this->_find($multiple);
	}

	public function findAllBy($field, $value, array $criteria = [])
	{
		return $this->findBy($field, $value, $criteria, true);
	}

	public function findByPk($id, array $criteria = [])
	{
		return $this->findBy(static::$primary_key, $id, $criteria);
	}

	public function count()
	{
		// Import criteria locally
		$criteria = $this->_criteria;

		unset($criteria['ORDER_BY'], $criteria['LIMIT'], $criteria['OFFSET']);
		$criteria['SELECT'] = 'COUNT(*) cnt';

		return (int) DB::find($criteria)->execute()->get('cnt');
	}

	public function save($run_validation = true, array $fields = null)
	{
		if ( ! $run_validation or $this->validate())
		{
			$data = $this->beforeSave($fields);

			if ($this->isNew())
			{
				list($this->{static::$primary_key}) = DB::insert(static::$table_name, array_keys($data))
					->values($data)
					->execute();
			}
			else
			{
				DB::update(static::$table_name)
					->set($data)
					->where(static::$primary_key, '=', $this->{static::$primary_key})
					->execute();
			}

			$this->afterSave();
			return true;
		}

		return false;
	}

	/**
	 * Mass assign values
	 * Example: $model->assign($_POST);
	 * Mass-assignment fields must have 'safe' attribute
	 *
	 * @param   array  $data
	 * @return  $this
	 */
	public function assign(array $data)
	{
		foreach ($data as $field => $value)
		{
			if ( ! empty(static::$fields[$field]['safe']))
			{
				$this->$field = $value;
			}
		}

		return $this;
	}

	public function validate()
	{
		$this->_errors = [];
		return true;
	}

	public function getErrors()
	{
		return $this->_errors;
	}

	protected function _find($multiple, $key = null)
	{
		$query = DB::find($this->_criteria);
		$start_time = microtime(true);
		$result = $query->execute();

		if (static::$profiling)
		{
			$sql = (string) $query;
			$time_delta = sprintf('%.3F', microtime(true) - $start_time);

			file_put_contents(
				APPPATH.'logs/ActiveRecord.log',
				"Time: $time_delta\n---\n$sql\n---\nPage url: {$_SERVER['REQUEST_URI']}\n=========================\n",
				FILE_APPEND
			);
		}

		if ( ! $multiple)
		{
			$result = $result->current();

			if ( ! $result)
				return false;

			return static::_createObject($result);
		}

		if ($key !== null)
		{
			$result = $result->as_array($key);
		}

		if ( ! count($result))
			return [];

		$arr = [];

		foreach ($result as $key => $row)
		{
			$arr[$key] = static::_createObject($row);
		}

		return $arr;
	}

	protected function afterLoad()
	{
		//
	}

	/**
	 * Extract object data for saving
	 *
	 * @param   array  $fields  Fields to extract
	 * @return  array
	 */
	protected function beforeSave(array $fields = null)
	{
		if ( ! $fields)
		{
			$fields = array_keys(static::$fields);
		}

		$result = [];

		foreach ($fields as $field)
		{
			if (isset($this->$field))
			{
				$result[$field] = $this->$field;
			}
		}

		unset($result[static::$primary_key]);
		return $result;
	}

	protected function afterSave()
	{
		//
	}

	public function __sleep()
	{
		$result = [];

		foreach (array_merge(array_keys(static::$fields), array_keys(static::$relations)) as $field)
		{
			if (property_exists($this, $field))
			{
				$result[] = $field;
			}
		}

		return $result;
	}
}