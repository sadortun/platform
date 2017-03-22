<?php

namespace Oro\Bundle\WorkflowBundle\Model;

use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\Mapping\ClassMetadataInfo;

use Symfony\Component\Translation\TranslatorInterface;

use Oro\Bundle\WorkflowBundle\Configuration\WorkflowConfiguration;
use Oro\Bundle\WorkflowBundle\Helper\WorkflowTranslationHelper;
use Oro\Bundle\WorkflowBundle\Serializer\Normalizer\WorkflowVariableNormalizer;

use Oro\Component\Action\Exception\AssemblerException;
use Oro\Component\Action\Model\AbstractAssembler as BaseAbstractAssembler;

class VariableAssembler extends BaseAbstractAssembler
{
    /**
     * @var WorkflowVariableNormalizer
     */
    protected $dataNormalizer;

    /**
     * @var VariableGuesser
     */
    protected $variableGuesser;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * @var ManagerRegistry
     */
    protected $managerRegistry;

    /**
     * @param WorkflowVariableNormalizer $dataNormalizer
     * @param VariableGuesser            $variableGuesser
     * @param TranslatorInterface        $translator
     * @param ManagerRegistry            $managerRegistry
     */
    public function __construct(
        WorkflowVariableNormalizer $dataNormalizer,
        VariableGuesser $variableGuesser,
        TranslatorInterface $translator,
        ManagerRegistry $managerRegistry
    ) {
        $this->dataNormalizer = $dataNormalizer;
        $this->variableGuesser = $variableGuesser;
        $this->translator = $translator;
        $this->managerRegistry = $managerRegistry;
    }

    /**
     * @param Workflow   $workflow
     * @param array|null $configuration
     *
     * @return Collection
     * @throws AssemblerException If configuration is invalid
     */
    public function assemble(Workflow $workflow, array $configuration = null)
    {
        $variables = new ArrayCollection();
        if (!is_array($configuration)) {
            return $variables;
        }

        $variableDefinitionsConfiguration = $this->getOption(
            $configuration,
            WorkflowConfiguration::NODE_VARIABLE_DEFINITIONS,
            []
        );
        $variablesConfiguration = $this->getOption(
            $variableDefinitionsConfiguration,
            WorkflowConfiguration::NODE_VARIABLES,
            []
        );

        $definitions = $this->parseDefinitions($variablesConfiguration);
        foreach ($definitions as $name => $options) {
            $variable = $this->assembleVariable($workflow, $name, $options);
            $variables->set($name, $variable);
        }

        return $variables;
    }

    /**
     * @param array $configuration
     *
     * @return array
     */
    protected function parseDefinitions(array $configuration)
    {
        $definitions = [];
        foreach ($configuration as $name => $options) {
            if (empty($options)) {
                $options = [];
            }

            $definition = [
                'label' => $this->getOption($options, 'label'),
                'type' => $this->getOption($options, 'type'),
                'value' => $this->getOption($options, 'value'),
                'options' => $this->getOption($options, 'options', []),
            ];

            $optionalKeys = ['property_path', 'entity_acl'];
            foreach ($optionalKeys as $key) {
                if (isset($options[$key])) {
                    $definition[$key] = $this->getOption($options, $key);
                }
            }

            $definitions[$name] = $definition;
        }

        return $definitions;
    }

    /**
     * @param Workflow $workflow
     * @param string   $name
     * @param array    $options
     *
     * @return Variable
     */
    protected function assembleVariable(Workflow $workflow, $name, array $options)
    {
        $this->assertOptions($options, ['type']);
        $this->assertVariableEntityAcl($options);

        $variable = new Variable();
        $variable
            ->setName($name)
            ->setLabel($options['label'])
            ->setType($options['type'])
            ->setPropertyPath($this->getOption($options, 'property_path'))
            ->setEntityAcl($this->getOption($options, 'entity_acl', []))
            ->setOptions($this->getOption($options, 'options', []));

        $this->validateVariable($variable);

        $denormalizedValue = $this->denormalizeVariable($workflow, $variable, $options);
        $variable->setValue($denormalizedValue);

        return $variable;
    }

    /**
     * @param string $class
     *
     * @return \Doctrine\Common\Persistence\ObjectManager|null
     * @throws AssemblerException
     */
    protected function getManagerForClass($class)
    {
        $entityManager = $this->managerRegistry->getManagerForClass($class);
        if (!$entityManager) {
            throw new AssemblerException(sprintf('Can\'t get entity manager for class %s', $class));
        }

        return $entityManager;
    }

    /**
     * @param array $options
     * @param string $rootClass
     * @param string $propertyPath
     *
     * @return array
     */
    protected function guessOptions(array $options, $rootClass, $propertyPath)
    {
        $guessedOptions = ['label', 'type', 'options'];

        $needsGuess = false;
        foreach ($guessedOptions as $option) {
            if (empty($options[$option])) {
                $needsGuess = true;
                break;
            }
        }

        if (!$needsGuess) {
            return $options;
        }

        $parameters = $this->variableGuesser->guessParameters($rootClass, $propertyPath);
        if (!$parameters) {
            return $options;
        }

        foreach ($guessedOptions as $option) {
            if (!empty($parameters[$option])) {
                if (empty($options[$option])) {
                    $options[$option] = $parameters[$option];
                } elseif ($option === 'label') {
                    $options[$option] = $this->guessOptionLabel($options, $parameters);
                }
            }
        }

        return $options;
    }

    /**
     * @param Variable $variable
     * @throws AssemblerException
     */
    protected function validateVariable(Variable $variable)
    {
        $this->assertVariableHasValidType($variable);

        $type = $variable->getType();
        if ('object' === $type || 'entity' === $type) {
            $this->assertParameterHasClassOption($variable);
        } else {
            $this->assertParameterHasNoOptions($variable, ['class']);
        }
    }

    /**
     * @param array $options
     * @throws AssemblerException
     */
    protected function assertVariableEntityAcl(array $options)
    {
        if (array_key_exists('entity_acl', $options) && $options['type'] !== 'entity') {
            throw new AssemblerException(
                sprintf(
                    'Variable "%s" with type "%s" can\'t have entity ACL',
                    $options['label'],
                    $options['type']
                )
            );
        }
    }

    /**
     * @param Variable $variable
     * @throws AssemblerException
     */
    protected function assertVariableHasValidType(Variable $variable)
    {
        $type = $variable->getType();
        $allowedTypes = ['bool', 'boolean', 'int', 'integer', 'float', 'string', 'array', 'object', 'entity'];

        if (!in_array($type, $allowedTypes, true)) {
            throw new AssemblerException(
                sprintf(
                    'Invalid variable type "%s", allowed types are "%s"',
                    $type,
                    implode('", "', $allowedTypes)
                )
            );
        }
    }

    /**
     * @param Workflow $workflow
     * @param Variable $variable
     * @param array    $options
     *
     * @return null|mixed|\Oro\Bundle\WorkflowBundle\Serializer\Normalizer\AttributeNormalizer
     * @throws AssemblerException
     */
    private function denormalizeVariable(Workflow $workflow, Variable $variable, array $options)
    {
        $type = $variable->getType();
        if (!in_array($type, ['object', 'entity'], true)) {
            return $this->dataNormalizer->denormalizeVariable($workflow, $variable, $options['value']);
        }

        if ('object' === $type) {
            return $this->denormalizeObjectVariable($options);
        }

        if ('entity' === $type) {
            return $this->denormalizeEntityVariable($options);
        }

        return null;
    }

    /**
     * @param array $options
     *
     * @return mixed
     */
    private function denormalizeObjectVariable(array $options)
    {
        $class = $this->getOption($options['options'], 'class');
        $propertyPath = $this->getOption($options, 'property_path');

        try {
            if ($propertyPath) {
                $object = new $class();
                $object->{$propertyPath} = $options['value'];
            } else {
                $object = new $class($options['value']);
            }

            return $object;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @param array $options
     *
     * @return mixed
     * @throws AssemblerException
     */
    private function denormalizeEntityVariable(array $options)
    {
        $class = $this->getOption($options['options'], 'class');
        $manager = $this->getManagerForClass($class);
        if (!$manager) {
            throw new AssemblerException(sprintf('Can\'t get entity manager for class %s', $class));
        }

        $identifier = $this->getOption($options['options'], 'identifier');
        if (!$identifier) {
            return $manager->find($class, $options['value']);
        }

        /** @var ClassMetadataInfo $metadata */
        $metadata = $manager->getClassMetadata($class);
        if ($metadata->isIdentifierComposite) {
            throw new AssemblerException(sprintf(
                'Entity with class %s has a composite identifier',
                $class
            ));
        }
        if (!$metadata->isUniqueField($identifier) && !in_array($identifier, $metadata->getIdentifier(), true)) {
            throw new AssemblerException(sprintf(
                'Field %s is not unique in entity with class %s',
                $identifier,
                $class
            ));
        }

        $repository = $manager->getRepository($class);

        return $repository->findOneBy([$identifier => $options['value']]);
    }

    /**
     * @param array $options
     * @param array $parameters
     * @return string
     */
    private function guessOptionLabel(array $options, array $parameters)
    {
        $domain = WorkflowTranslationHelper::TRANSLATION_DOMAIN;

        if ($this->translator->trans($options['label'], [], $domain) === $options['label']) {
            $options['label'] = $parameters['label'];
        }

        return $options['label'];
    }
}
