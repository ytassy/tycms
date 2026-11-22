<?php

declare(strict_types=1);

namespace YTassy\Tycms\Lib\Forms;

use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Exceptions\TechException;

/**
 * Trusted SQL-backed option source.
 *
 * Its query is authored in server code. Dynamic values must be returned by
 * $parameters and are passed to Database::select() separately, using its %
 * markers. SQL text and parameters are never exported to Vue.
 */
final class SqlFormOptionsProvider extends BaseApplicationItem implements FormOptionsProvider
{
    /** @var array<int,mixed>|\Closure(FormOptionContext):array<int,mixed> */
    private readonly array|\Closure $parameters;

    /**
     * @param array<int,mixed>|\Closure(FormOptionContext):array<int,mixed> $parameters
     */
    public function __construct(
        BaseApplicationItem|Application $app,
        private readonly string $query,
        array|\Closure $parameters = [],
        private readonly string $valueColumn = 'value',
        private readonly string $labelColumn = 'label',
    ) {
        parent::__construct($app);
        $this->parameters = $parameters;

        if (preg_match('/^\s*(?:SELECT|WITH)\b/i', $this->query) !== 1) {
            throw new TechException('A SQL form options provider only accepts a SELECT or WITH query.');
        }
    }

    public function resolve(FormOptionContext $context): array
    {
        $parameters = $this->parameters instanceof \Closure
            ? ($this->parameters)($context)
            : $this->parameters;

        if (!is_array($parameters) || !array_is_list($parameters)) {
            throw new TechException('SQL form option parameters must be a list.');
        }

        $rows = $this->db->select($this->query, $parameters);
        $options = [];
        foreach ($rows as $row) {
            if (!property_exists($row, $this->valueColumn) || !property_exists($row, $this->labelColumn)) {
                throw new TechException("The SQL form options query must return '{$this->valueColumn}' and '{$this->labelColumn}' columns.");
            }

            $label = $row->{$this->labelColumn};
            if (!is_string($label)) {
                $label = (string) $label;
            }

            $options[] = [
                'value' => $row->{$this->valueColumn},
                'label' => $label,
            ];
        }

        return $options;
    }
}
