<?php

/**
 * Abstract class for union, intersection and diff of sets 
 * 
 * @author Ivan Shumkov
 * @package Rediska
 * @version @package_version@
 * @link http://rediska.geometria-lab.net
 * @licence http://www.opensource.org/licenses/bsd-license.php
 */
abstract class Rediska_Command_CompareSets extends Rediska_Command_Abstract
{
	protected $_storeConnection;

	protected $_command;
    protected $_storeCommand;

    public function create(array $names, $storeName = null) 
    {
        if (empty($names)) {
            throw new Rediska_Command_Exception('You must specify sets');
        }

        $connections = array();
        $namesByConnections = array();
        foreach ($names as $name) {
            $connection = $this->_rediska->getConnectionByKeyName($name);
            $connectionAlias = $connection->getAlias();
            if (!array_key_exists($connectionAlias, $connections)) {
                $connections[$connectionAlias] = $connection;
                $namesByConnections[$connectionAlias] = array();
            }
            $namesByConnections[$connectionAlias][] = $name;
        }

        if (count($connections) == 1) {
        	$connectionValues = array_values($connections);
            $connection = $connectionValues[0];

            if (!is_null($storeName)) {
                $storeConnection = $this->_rediska->getConnectionByKeyName($storeName);
                if ($storeConnection->getAlias() == $connection->getAlias()) {
                	$command = "{$this->_storeCommand} {$this->_rediska->getOption('namespace')}$storeName";
                } else {
                	$this->setAtomic(false);
                	$this->_storeConnection = $storeConnection;
                	$command = $this->_command;
                }
            } else {
            	$command = $this->_command;
            }

            $connectionKeys = array_keys($connections);
            $connectionAlias = $connectionKeys[0];

            foreach($namesByConnections[$connectionAlias] as $name) {
                $command .= " {$this->_rediska->getOption('namespace')}$name";
            }

            return new Rediska_Connection_Exec($connection, $command);
        } else {
            $this->setAtomic(false);

            $commands = array();
            foreach($namesByConnections as $connectionAlias => $keys) {
                foreach ($keys as $key) {
                    $command = "SMEMBERS {$this->_rediska->getOption('namespace')}$key";
                    $commands[] = new Rediska_Connection_Exec($connections[$connectionAlias], $command);
                }
            }

            return $commands;
        }
    }

    abstract protected function _compareSets($sets);

    public function parseResponses($responses)
    {
		if (!$this->isAtomic()) {
    		if ($this->_storeConnection) {
                $values = $responses[0];
    		} else {
    			$values = array_values($this->_compareSets($responses));
    		}

    		$unserializedValues = array();
            foreach($values as $value) {
                $unserializedValues[] = $this->_rediska->getSerializer()->unserialize($value);
            }

    		if (is_null($this->storeName)) {
    			return $unserializedValues;
    		} else {
    			$this->_rediska->delete($this->storeName);
                foreach($unserializedValues as $value) {
                    $this->_rediska->addToSet($this->storeName, $value);
                }
                return true;
    		}
        } else {
            $reply = $responses[0];
            if (is_null($this->storeName)) {
                foreach($reply as &$value) {
                    $value = $this->_rediska->getSerializer()->unserialize($value);
                }
            } else {
                $reply = (boolean)$reply;
            }

            return $reply;
        }
    }
}