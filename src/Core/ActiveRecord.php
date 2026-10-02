<?php
namespace App\Core;

use InvalidArgumentException;
use PDO;

class ActiveRecord
{
    protected static $db;
    protected static $tabla = '';
    protected static $columnasDB = [];
    protected static $alertas = [];

    public static function setDB($database) { self::$db = $database; }
    public static function setAlerta($tipo, $mensaje) { static::$alertas[$tipo][] = $mensaje; }
    public static function getAlertas() { return static::$alertas; }
    public function validar() { static::$alertas = []; return static::$alertas; }

    // Trusted raw SQL escape hatch retained for legacy callers.
    public static function consultarSQL($query)
    {
        return static::hidratar(self::$db->query($query)->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function hidratar(array $rows): array
    {
        $objects = [];
        foreach ($rows as $row) { $objects[] = static::crearObjeto($row); }
        return $objects;
    }

    private static function consultarPreparado(string $query, array $values = []): array
    {
        $statement = self::$db->prepare($query);
        $statement->execute($values);
        return static::hidratar($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function tabla(): string
    {
        if (!is_string(static::$tabla) || !preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', static::$tabla)) {
            throw new InvalidArgumentException('Invalid table identifier.');
        }
        return static::$tabla;
    }

    private static function columna($name): string
    {
        if (!is_string($name) || !in_array($name, static::$columnasDB, true)
            || !preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $name)) {
            throw new InvalidArgumentException('Invalid column identifier.');
        }
        return $name;
    }

    private static function orden($order): string
    {
        if (!is_string($order) || !in_array($order, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Invalid sort direction.');
        }
        return $order;
    }

    private static function entero($value): int
    {
        if (!is_int($value) && (!is_string($value) || !preg_match('/\A[0-9]+\z/D', $value))) {
            throw new InvalidArgumentException('Invalid pagination value.');
        }
        if (filter_var((string) $value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            throw new InvalidArgumentException('Invalid pagination value.');
        }
        return (int) $value;
    }

    private static function valorFiltroLegacy($value)
    {
        return $value === null || $value === false ? '' : $value;
    }

    protected static function crearObjeto($registro)
    {
        $objeto = new static;
        foreach ($registro as $key => $value) {
            if (property_exists($objeto, $key)) { $objeto->$key = $value; }
        }
        return $objeto;
    }

    public function atributos()
    {
        $atributos = [];
        foreach (static::$columnasDB as $columna) {
            if ($columna === 'id') { continue; }
            $atributos[$columna] = $this->$columna;
        }
        return $atributos;
    }

    public function sanitizarAtributos()
    {
        $sanitizado = [];
        foreach ($this->atributos() as $key => $value) {
            $quoted = self::$db->quote((string) $value);
            $sanitizado[$key] = substr($quoted, 1, -1);
        }
        return $sanitizado;
    }

    public function sincronizar($args = [])
    {
        foreach ($args as $key => $value) {
            if (property_exists($this, $key) && !is_null($value)) { $this->$key = $value; }
        }
    }

    public function guardar()
    {
        return !is_null($this->id) ? $this->actualizar() : $this->crear();
    }

    public static function all($order = 'DESC')
    {
        return static::consultarPreparado('SELECT * FROM ' . static::tabla() . ' ORDER BY id ' . static::orden($order));
    }

    public static function find($id)
    {
        // A null id produced incomplete SQL in the legacy implementation.
        $query = 'SELECT * FROM ' . static::tabla() . ' WHERE id = ' . ($id === null ? '' : '?');
        $rows = static::consultarPreparado($query, $id === null ? [] : [$id]);
        return array_shift($rows);
    }

    public static function get($limite)
    {
        // Preserve the invalid legacy clause order, including its database error.
        $rows = static::consultarPreparado('SELECT * FROM ' . static::tabla() . ' LIMIT ' . static::entero($limite) . ' ORDER BY id DESC');
        return array_shift($rows);
    }

    public static function where($columna, $valor)
    {
        $rows = static::consultarPreparado('SELECT * FROM ' . static::tabla() . ' WHERE ' . static::columna($columna) . ' = ?', [static::valorFiltroLegacy($valor)]);
        return array_shift($rows);
    }

    public static function whereArray($array = [])
    {
        $conditions = [];
        foreach ($array as $column => $value) { $conditions[] = static::columna($column) . ' = ?'; }
        // Empty input intentionally retains the invalid trailing WHERE.
        return static::consultarPreparado('SELECT * FROM ' . static::tabla() . ' WHERE ' . implode(' AND ', $conditions), array_map(static::valorFiltroLegacy(...), array_values($array)));
    }

    public static function ordenar($columna, $orden)
    {
        return static::consultarPreparado('SELECT * FROM ' . static::tabla() . ' ORDER BY ' . static::columna($columna) . ' ' . static::orden($orden));
    }

    public static function total($columna = '', $valor = '')
    {
        $query = 'SELECT COUNT(*) FROM ' . static::tabla();
        $values = [];
        if ($columna) { $query .= ' WHERE ' . static::columna($columna) . ' = ?'; $values[] = static::valorFiltroLegacy($valor); }
        $statement = self::$db->prepare($query);
        $statement->execute($values);
        return $statement->fetchColumn();
    }

    public static function paginar($por_pagina, $offset)
    {
        $statement = self::$db->prepare('SELECT * FROM ' . static::tabla() . ' ORDER BY id DESC LIMIT ? OFFSET ?');
        $statement->bindValue(1, static::entero($por_pagina), PDO::PARAM_INT);
        $statement->bindValue(2, static::entero($offset), PDO::PARAM_INT);
        $statement->execute();
        return static::hidratar($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function crear()
    {
        $attributes = $this->atributos();
        foreach ($attributes as $column => $_) { static::columna($column); }
        $values = array_map(static fn($value): string => (string) $value, array_values($attributes));
        if ($values !== []) {
            $values[0] = ' ' . $values[0];
            $last = array_key_last($values);
            $values[$last] .= ' ';
        }
        $query = 'INSERT INTO ' . static::tabla() . ' (' . implode(', ', array_keys($attributes)) . ') VALUES ('
            . implode(', ', array_fill(0, count($attributes), '?')) . ')';
        $statement = self::$db->prepare($query);
        $result = $statement->execute($values);
        return ['resultado' => $result, 'id' => (int) self::$db->lastInsertId()];
    }

    public function actualizar()
    {
        $attributes = $this->atributos();
        $assignments = [];
        foreach ($attributes as $column => $_) { $assignments[] = static::columna($column) . ' = ?'; }
        $values = array_map(static fn($value): string => (string) $value, array_values($attributes));
        $values[] = (string) $this->id;
        $statement = self::$db->prepare('UPDATE ' . static::tabla() . ' SET ' . implode(', ', $assignments) . ' WHERE id = ? LIMIT 1');
        return $statement->execute($values);
    }

    public function eliminar()
    {
        $statement = self::$db->prepare('DELETE FROM ' . static::tabla() . ' WHERE id = ? LIMIT 1');
        return $statement->execute([(string) $this->id]);
    }
}
