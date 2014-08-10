<?php

namespace Stillman\Kohana;

use Arr;
use Database;

class ActiveRecord
{
	// Relation types

	const HAS_ONE    = 'HAS ONE';
	const BELONGS_TO = 'BELONGS TO';

	// Join types

	const LEFT_JOIN  = 'LEFT JOIN';
	const RIGHT_JOIN = 'RIGHT JOIN';
	const INNER_JOIN = 'INNER JOIN';

	public static $table_name;

	// Set to true to enable profiling
	public static $profiling = false;

	public static $fields = [];

	public static $default_filter = null;

	public static $relations = [];

	public static $primary_key = 'Id';

	// Database instance
	protected $_database_instance = null;

	protected $_criteria = [];

	protected $_errors = [];

	// Changed values
	protected $_changed = [];

	/**
	 * @var string Current scenario
	 * @since 1.1.2
	 */
	protected $_scenario = 'default';

	/**
	 * @var array Actual data
	 */
	protected $_data = [];

	/**
	 * @var array Related objects
	 */
	protected $_related_objects = [];

	public static function model($class = null)
	{
		if ($class === null)
			return new static;

		return new $class;
	}

	public static function findBySql($sql, array $params = [])
	{
		return static::_findBySql($sql, $params, false);
	}

	public static function findAllBySql($sql, array $params = [])
	{
		return static::_findBySql($sql, $params, true);
	}

	protected static function _findBySql($sql, array $params = [], $multiple = true)
	{
		$data = \DB::query(\Database::SELECT, $sql)
			->parameters($params)
			->execute();

		if ($multiple)
		{
			$result = [];

			foreach ($data as $row)
			{
				$result [] = static::_createObject($row);
			}

			return $result;
		}
		else
		{
			if ( ! count($data))
				return null;

			return static::_createObject($data->current());
		}
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
			if (array_key_exists($field, $array))
			{
				// Set object properties directly
				$object->_data[$field] = $array[$field];
			}
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
	protected static function _createObject($values)
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

	/**
	 * Remove named condition added by ActiveRecord::addCondition method.
	 *
	 * Example:
	 *
	 * // Adding named condition
	 * $model->addCondition(['name' => 'field = 1']);
	 * // Remove the previously added condition
	 * $model->removeCondition('name');
	 *
	 * @since  1.1.1
	 *
	 * @param  string  $name  Condition name
	 * @return $this
	 */
	public function removeCondition($name)
	{
		unset($this->_criteria['WHERE'][$name]);
		return $this;
	}

	/**
	 * Load relation along with object
	 *
	 * @throws \Stillman\Kohana\ActiveRecord\Exception;
	 *
	 * @param  string  $alias          Relation name
	 * @param  string  $join_type      Join type
	 * @param  array   $filter_params  Filter parameters (if there are)
	 *
	 * @return $this
	 */
	public function with($alias, $join_type = self::LEFT_JOIN, array $filter_params = [])
	{
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

		$table2alias = $alias;
		$relation_type = $class1::$relations[$_]['relation_type'];

		$filter = ! empty($class1::$relations[$_]['filter']) ? ' AND '.$class1::$relations[$_]['filter'] : '';

		if ($filter)
		{
			$filter = str_replace('[relation]', "`$table2alias`", $filter);

			if ($filter_params)
			{
				$db = Database::instance();
				$filter = strtr($filter, array_map([$db, 'quote'], $filter_params));
			}
		}

		switch ($relation_type)
		{
			case static::HAS_ONE:
				$criteria['JOIN'][$alias] = "$join_type ".$class2::$table_name." AS `$table2alias` ON `$table2alias`.`".$class1::$relations[$_]['fk']."` = `$table1alias`.`".$class1::$primary_key.'`'.$filter;
				break;

			case static::BELONGS_TO:
				$criteria['JOIN'][$alias] = "$join_type ".$class2::$table_name." AS `$table2alias` ON `$table1alias`.`".$class1::$relations[$_]['fk']."` = `$table2alias`.`".$class2::$primary_key.'`'.$filter;
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

	/**
	 * @return $this
	 */
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
		$this->_criteria['WHERE'] = ["t.$field = $param_name"];
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

	/**
	 * @param bool $execute_before_delete  Whether beforeDelete event should be executed
	 * @param bool $execute_after_delete  Whether afterDelete event should be executed
	 * @return bool
	 * @throws ActiveRecord\Exception
	 */
	public function delete($execute_before_delete = true, $execute_after_delete = true)
	{
		if ($this->isNew())
		{
			throw new ActiveRecord\Exception('Can not delete object that haven\'t been saved yet');
		}

		if ($execute_before_delete)
		{
			$this->beforeDelete();
		}

		$result = (bool) \DB::delete(static::$table_name)
			->where(static::$primary_key, '=', $this->pk())
			->execute($this->_database_instance);

		if ($result and $execute_after_delete)
		{
			// Execute this only if row really was deleted
			$this->afterDelete();
		}

		return $result;
	}

	public function deleteAll()
	{
		return \DB::delete_rows(['FROM' => static::$table_name] + $this->_criteria)
			->execute($this->_database_instance);
	}

	public function count()
	{
		// Import criteria locally
		$criteria = $this->_criteria;

		unset($criteria['ORDER_BY'], $criteria['LIMIT'], $criteria['OFFSET']);
		$criteria['SELECT'] = 'COUNT(*) cnt';

		return (int) \DB::find($criteria)->execute()->get('cnt');
	}

	/**
	 * Get the current scenario
	 *
	 * @since 1.1.2
	 *
	 * @return string
	 */
	public function getScenario()
	{
		return $this->_scenario;
	}

	/**
	 * Set the current scenario
	 *
	 * @since 1.1.2
	 *
	 * @param  string  $scenario
	 * @return $this
	 */
	public function setScenario($scenario)
	{
		$this->_scenario = $scenario;
		return $this;
	}

	public function save($run_validation = true)
	{
		$this->beforeSave();

		if ( ! $run_validation or $this->validate())
		{
			if ($this->_changed)
			{
				if ($this->isNew())
				{
					// Adding a new record
					list($this->{static::$primary_key}) = \DB::insert_row(
						static::$table_name,
						$this->_changed,
						$this->_database_instance
					);

					$this->afterCreate();
				}
				else
				{
					// Updating existing record
					\DB::update(static::$table_name)
						->set($this->_changed)
						->where(static::$primary_key, '=', $this->pk())
						->execute($this->_database_instance);

					$this->afterUpdate();
				}

				// Reset changed values
				$this->_changed = [];
			}

			$this->afterSave();
			return true;
		}

		return false;
	}

	/**
	 * Mass assign values
	 * Example: $model->populate($_POST);
	 * Mass-assignment fields must have 'safe' attribute
	 *
	 * @param   array  $data
	 * @return  $this
	 */
	public function populate(array $data)
	{
		foreach (static::$fields as $field => $params)
		{
			if (isset($params['safe']) and isset($data[$field]))
			{
				$this->set($field, $data[$field]);
			}
		}

		return $this;
	}

	/**
	 * Validate the model
	 *
	 * @return  bool  Was the validation success
	 */
	public function validate()
	{
		$this->resetErrors();
		$this->_validate();
		return ! $this->hasErrors();
	}

	/**
	 * Return model properties as array
	 *
	 * @param  array|null $fields Field names to extract (since 1.3.0)
	 * @return array
	 */
	public function asArray(array $fields = null)
	{
		if ($fields)
			return Arr::extract($this->_data, $fields);

		$result = $this->_data;

		foreach ($this->_related_objects as $key => $object)
		{
			$result[$key] = $object->asArray();
		}

		return $result;
	}

	/**
	 * Retrieve the validation errors
	 *
	 * @return  array
	 */
	public function getErrors()
	{
		return $this->_errors;
	}

	public function hasErrors()
	{
		return (bool) $this->_errors;
	}

	public function addError($field, $error, $replace = false)
	{
		if ($replace or ! isset($this->_errors[$field]))
		{
			$this->_errors[$field] = $error;
		}
	}

	public function resetErrors()
	{
		$this->_errors = [];
	}

	/**
	 * Lazy load a relation
	 *
	 * @param   string  $relation_name The name of a relation
	 * @return  $this
	 */
	protected function _loadRelation($relation_name)
	{
		$relation = static::$relations[$relation_name];
		$class = $relation['class'];

		switch ($relation['relation_type'])
		{
			case static::HAS_ONE:
			{
				$this->_related_objects[$relation_name] = $class::model()
					->findBy($this->{$relation['fk']}, $this->pk());

				break;
			}

			case static::BELONGS_TO:
			{
				$this->_related_objects[$relation_name] = $class::model()
					->findByPk($this->{$relation['fk']});

				break;
			}
		}

		return $this;
	}

	protected function _find($multiple, $key = null)
	{
		$query = \DB::find($this->_criteria);
		$start_time = microtime(true);
		$result = $query->execute($this->_database_instance);

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
			{
				// Nothing found
				return null;
			}

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

	public function __get($key)
	{
		if (isset(static::$fields[$key]))
		{
			return array_key_exists($key, $this->_data)
				? $this->_data[$key]
				: static::$fields[$key]['default'];
		}
		elseif (isset(static::$relations[$key]))
		{
			if ( ! isset($this->_related_objects[$key]))
			{
				// Relation is defined, but not loaded yet. Lazy load it
				$this->_loadRelation($key);
			}

			return $this->_related_objects[$key];
		}
		elseif (method_exists($this, 'get'.$key))
		{
			return $this->{'get'.$key}();
		}

		throw new ActiveRecord\Exception('Class '.get_class($this).' does not have property '.$key);
	}

	/**
	 * @param string $field
	 * @param mixed $value
	 * @param bool $preproccess
	 * @param bool $mark_as_changed
	 * @return $this
	 * @throws ActiveRecord\Exception
	 * @since 1.3.0
	 */
	public function set($field, $value, $preproccess = true, $mark_as_changed = true)
	{
		if (isset(static::$fields[$field]))
		{
			$pre_filter = isset(static::$fields[$field]['safe']) ? static::$fields[$field]['safe'] : null;

			if ($pre_filter === true)
			{
				$pre_filter = static::$default_filter;
			}

			if ($preproccess and $pre_filter)
			{
				// Prefilter the value
				$value = call_user_func($pre_filter, $value, $this);
			}

			$this->_data[$field] = $value;

			if ($mark_as_changed)
			{
				// Mark value as changed
				$this->_changed[$field] = $value;
			}

			return $this;
		}
		else
		{
			throw new ActiveRecord\Exception('Class '.get_class($this).' does not have property '.$field);
		}
	}

	public function __set($key, $value)
	{
		if (isset(static::$fields[$key]))
		{
			$this->set($key, $value);
		}
		elseif (isset(static::$relations[$key]))
		{
			$this->_related_objects[$key] = $value;
		}
		elseif (method_exists($this, 'set'.$key))
		{
			$this->{'set'.$key}($value);
		}
		else
		{
			throw new ActiveRecord\Exception('Class '.get_class($this).' does not have property '.$key);
		}
	}

	public function __isset($key)
	{
		return (isset($this->_data[$key]) or isset($this->_related_objects[$key]));
	}

	public function __unset($key)
	{
		unset($this->_data[$key], $this->_changed[$key], $this->_related_objects[$key]);
	}

	protected function afterLoad() {}

	protected function beforeSave() {}

	protected function afterSave() {}

	protected function afterCreate() {}

	protected function afterUpdate() {}

	protected function beforeDelete() {}

	protected function afterDelete() {}

	protected function _validate() {}
}