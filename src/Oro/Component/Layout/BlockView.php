<?php

namespace Oro\Component\Layout;

use Symfony\Component\Form\FormView;

/**
 * @method BlockView getParent()
 * @property BlockView[] children
 */
class BlockView extends FormView
{
    /**
     * All layout views.
     *
     * @var BlockView[]
     */
    public $layoutViews = [];

    /**
     * @param BlockView $parent
     */
    public function __construct(BlockView $parent = null)
    {
        parent::__construct($parent);
        unset($this->vars['value']);
    }

    /**
     * Returns a child from any level of a hierarchy by id (implements \ArrayAccess)
     *
     * @param string $id The child id
     *
     * @return BlockView The child view
     *
     * @throws \OutOfBoundsException if a child does not exist
     */
    public function offsetGet($id)
    {
        if (isset($this->layoutViews[$id])) {
            return $this->layoutViews[$id];
        };

        throw new \OutOfBoundsException(sprintf('Undefined index: %s.', $id));
    }

    /**
     * Checks whether the given child exists on any level of a hierarchy (implements \ArrayAccess)
     *
     * @param string $id The child id
     *
     * @return bool Whether the child view exists
     */
    public function offsetExists($id)
    {
        return isset($this->layoutViews[$id]);
    }

    /**
     * Implements \ArrayAccess
     *
     * @throws \BadMethodCallException always as setting a child by id is not allowed
     */
    public function offsetSet($id, $value)
    {
        throw new \BadMethodCallException('Not supported');
    }

    /**
     * Implements \ArrayAccess
     *
     * @throws \BadMethodCallException always as removing a child by id is not allowed
     */
    public function offsetUnset($id)
    {
        throw new \BadMethodCallException('Not supported');
    }
}
