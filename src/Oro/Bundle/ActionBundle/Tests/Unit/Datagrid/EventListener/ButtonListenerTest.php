<?php

namespace Oro\Bundle\ActionBundle\Tests\Unit\Datagrid\EventListener;

use Symfony\Bridge\Twig\Tests\Extension\Fixtures\StubTranslator;

use Oro\Bundle\ActionBundle\Button\ButtonContext;
use Oro\Bundle\ActionBundle\Button\ButtonInterface;
use Oro\Bundle\ActionBundle\Button\ButtonsCollection;
use Oro\Bundle\ActionBundle\Button\ButtonSearchContext;
use Oro\Bundle\ActionBundle\Button\OperationButton;
use Oro\Bundle\ActionBundle\Datagrid\EventListener\ButtonListener;
use Oro\Bundle\ActionBundle\Datagrid\Provider\MassActionProviderInterface;
use Oro\Bundle\ActionBundle\Datagrid\Provider\MassActionProviderRegistry;
use Oro\Bundle\ActionBundle\Extension\ButtonProviderExtensionInterface;
use Oro\Bundle\ActionBundle\Helper\ContextHelper;
use Oro\Bundle\ActionBundle\Helper\OptionsHelper;
use Oro\Bundle\ActionBundle\Model\ActionData;
use Oro\Bundle\ActionBundle\Model\Operation;
use Oro\Bundle\ActionBundle\Model\OperationDefinition;
use Oro\Bundle\ActionBundle\Provider\ButtonProvider;
use Oro\Bundle\ActionBundle\Tests\Unit\Stub\StubButton;
use Oro\Bundle\DataGridBundle\Datagrid\Common\DatagridConfiguration;
use Oro\Bundle\DataGridBundle\Datasource\Orm\OrmDatasource;
use Oro\Bundle\DataGridBundle\Datasource\ResultRecord;
use Oro\Bundle\DataGridBundle\Extension\Action\ActionExtension;
use Oro\Bundle\DataGridBundle\Extension\Action\Event\ConfigureActionsBefore;
use Oro\Bundle\EntityBundle\ORM\EntityClassResolver;

class ButtonListenerTest extends \PHPUnit_Framework_TestCase
{
    const PROVIDER_ALIAS = 'test_mass_action_provider';
    const TEST_ROUTE = 'test_route';

    /** @var \PHPUnit_Framework_MockObject_MockObject|ButtonProvider */
    protected $buttonProvider;

    /** @var \PHPUnit_Framework_MockObject_MockObject|EntityClassResolver */
    protected $entityClassResolver;

    /** @var \PHPUnit_Framework_MockObject_MockObject|MassActionProviderRegistry */
    protected $massActionProviderRegistry;

    /** @var ButtonListener */
    protected $listener;

    /**
     * {@inheritdoc}
     */
    protected function setUp()
    {
        $this->buttonProvider = $this->getMockBuilder(ButtonProvider::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->entityClassResolver = $this->getMockBuilder(EntityClassResolver::class)
            ->disableOriginalConstructor()
            ->getMock();

        /** @var \PHPUnit_Framework_MockObject_MockObject|ContextHelper $contextHelper */
        $contextHelper = $this->getMockBuilder(ContextHelper::class)
            ->disableOriginalConstructor()
            ->getMock();
        $contextHelper->expects($this->any())
            ->method('getContext')
            ->willReturn(
                [
                    ContextHelper::ROUTE_PARAM => self::TEST_ROUTE,
                    ContextHelper::ENTITY_ID_PARAM => null,
                    ContextHelper::ENTITY_CLASS_PARAM => null,
                    ContextHelper::DATAGRID_PARAM => null,
                    ContextHelper::GROUP_PARAM => null,
                    ContextHelper::FROM_URL_PARAM => null,
                ]
            );

        $provider = $this->createMock(MassActionProviderInterface::class);
        $provider->expects($this->any())
            ->method('getActions')
            ->willReturn(['test_config' => ['label' => 'test_label']]);

        $this->massActionProviderRegistry = $this->getMockBuilder(MassActionProviderRegistry::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->massActionProviderRegistry->expects($this->any())
            ->method('getProvider')
            ->with(self::PROVIDER_ALIAS)
            ->willReturn($provider);

        /* @var $optionsHelper OptionsHelper|\PHPUnit_Framework_MockObject_MockObject */
        $optionsHelper = $this->getMockBuilder(OptionsHelper::class)
            ->disableOriginalConstructor()
            ->getMock();
        $optionsHelper->expects($this->any())
            ->method('getFrontendOptions')
            ->willReturn([
                'options' => [
                    'option1' => 'value1',
                    'option2' => 'value2',
                ],
                'data' => [
                    'key1' => 'value1',
                ],
            ]);

        $this->listener = new ButtonListener(
            $this->buttonProvider,
            $contextHelper,
            $this->massActionProviderRegistry,
            $optionsHelper,
            $this->entityClassResolver,
            new StubTranslator()
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function tearDown()
    {
        unset(
            $this->listener,
            $this->massActionProviderRegistry,
            $this->buttonProvider,
            $this->entityClassResolver
        );
    }

    /**
     * @param DatagridConfiguration $config
     * @param ButtonsCollection $buttonCollection
     * @param bool $expected
     * @param array $expectedConfiguration
     *
     * @dataProvider onConfigureActionsProvider
     */
    public function testOnConfigureActions(
        DatagridConfiguration $config,
        ButtonsCollection $buttonCollection,
        $expected,
        array $expectedConfiguration = []
    ) {
        $this->buttonProvider->expects($this->once())->method('match')->willReturn($buttonCollection);

        $this->listener->onConfigureActions(new ConfigureActionsBefore($config));

        if ($expected) {
            $options = $config->offsetGetOr('options');

            $this->assertInternalType('array', $options);
            $this->assertArrayHasKey('urlParams', $options);
            $this->assertArrayHasKey('originalRoute', $options['urlParams']);
            $this->assertEquals(self::TEST_ROUTE, $options['urlParams']['originalRoute']);

            $this->assertNotEmpty($config->offsetGetOr('actions'));
            $this->assertNotEmpty($config->offsetGetOr('action_configuration'));

            foreach ($expectedConfiguration as $name => $params) {
                $this->assertNotEmpty($config->offsetGetOr($name));
                $this->assertEquals($params, $config->offsetGetOr($name));
            }
        } else {
            $this->assertEmpty($config->offsetGetOr('options'));
            $this->assertEmpty($config->offsetGetOr('actions'));
            $this->assertEmpty($config->offsetGetOr('action_configuration'));
        }
    }

    /**
     * @param DatagridConfiguration $datagridConfig
     * @param ResultRecord $record
     * @param ButtonsCollection $buttonsCollection
     * @param array $expectedActions
     * @param array $groups
     *
     * @dataProvider getRowConfigurationProvider
     */
    public function testGetRowConfiguration(
        DatagridConfiguration $datagridConfig,
        ResultRecord $record,
        ButtonsCollection $buttonsCollection,
        array $expectedActions,
        array $groups = null
    ) {
        $this->buttonProvider->expects($this->any())->method('match')->willReturn($buttonsCollection);

        if ($groups) {
            $this->listener->setGroups($groups);
        }

        $this->listener->onConfigureActions(new ConfigureActionsBefore($datagridConfig));

        $actionConfigurationCallback = $datagridConfig->offsetGetOr(ActionExtension::ACTION_CONFIGURATION_KEY, []);

        if ($actionConfigurationCallback) {
            $this->assertInstanceOf('Closure', $actionConfigurationCallback);

            $this->assertEquals($expectedActions, call_user_func($actionConfigurationCallback, $record, []));
        } else {
            $this->assertEmpty($actionConfigurationCallback);
        }
    }

    /**
     * @return array
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function onConfigureActionsProvider()
    {
        return [
            'configure with provider' => [
                'config' => DatagridConfiguration::create([
                    'name' => 'datagrid1',
                    'source' => [
                        'type' => OrmDatasource::TYPE,
                    ],
                ]),
                'buttonCollection' => $this->createButtonsCollection(
                    [
                        $this->createOperationButton(
                            'test_operation',
                            true,
                            ['mass_action_provider' => self::PROVIDER_ALIAS]
                        )
                    ]
                ),
                'expected' => true,
                'expectedConfiguration' => [
                    'mass_actions' => ['test_operationtest_config' => ['label' => 'test_label']]
                ]
            ],
            'configure with single mass action' => [
                'config' => DatagridConfiguration::create([
                    'name' => 'datagrid1',
                    'source' => [
                        'type' => OrmDatasource::TYPE,
                    ],
                ]),
                'buttonCollection' => $this->createButtonsCollection(
                    [
                        $this->createOperationButton(
                            'test_operation',
                            true,
                            ['mass_action' => ['label' => 'test_mass_action_label']]
                        )
                    ]
                ),
                'expected' => true,
                'expectedConfiguration' => [
                    'mass_actions' => ['test_operation' => ['label' => 'test_mass_action_label']]
                ]
            ],
            'configure with single action' => [
                'config' => DatagridConfiguration::create([
                    'name' => 'datagrid1',
                    'source' => [
                        'type' => OrmDatasource::TYPE,
                    ],
                ]),
                'buttonCollection' => $this->createButtonsCollection(
                    [
                        $this->createOperationButton(
                            'action3',
                            true,
                            ['data' => ['key1' => 'value1']],
                            'Action 3 label'
                        )
                    ]
                ),
                'expected' => true,
                'expectedConfiguration' => [
                    'actions' => [
                        'action3' => $this->getRowActionConfig(
                            '[trans]Action 3 label[/trans]',
                            ['key1' => 'value1']
                        )
                    ],
                ]
            ],
            'should not replace existing default action' => [
                'config' => DatagridConfiguration::create([
                    'name' => 'datagrid1',
                    'actions' => [
                        'action3' => [
                            'label' => 'default action3'
                        ]
                    ],
                    'source' => [
                        'type' => OrmDatasource::TYPE,
                    ],
                ]),
                'buttonCollection' => $this->createButtonsCollection(
                    [
                        $this->createButton(
                            'action3',
                            true,
                            [
                                'getLabel' => 'Action 3 label'
                            ]
                        ),
                        $this->createOperationButton(
                            'test_operation',
                            true,
                            ['label' => 'test_mass_action_label']
                        )
                    ]
                ),
                'expected' => true,
                'expectedConfiguration' => [
                    'actions' => [
                        'action3' => ['label' => 'default action3'],
                        'test_operation' => $this->getRowActionConfig('[trans][/trans]'),
                    ]
                ]
            ],
            'not configure' => [
                'config' => DatagridConfiguration::create([
                    'name' => 'datagrid1',
                    'source' => [
                        'type' => OrmDatasource::TYPE,
                    ],
                ]),
                'buttonCollection' => $this->createButtonsCollection([]),
                'expected' => false
            ]
        ];
    }

    /**
     * @return array
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function getRowConfigurationProvider()
    {
        return [
            'no actions' => [
                'config' => DatagridConfiguration::create(['name' => 'datagrid_name']),
                'record' => new ResultRecord(['id' => 1]),
                'buttonCollection' => $this->createButtonsCollection([]),
                'expectedActions' => [],
                'groups' => null,
            ],
            'no actions and group1' => [
                'config' => DatagridConfiguration::create(['name' => 'datagrid_name']),
                'record' => new ResultRecord(['id' => 1]),
                'buttonCollection' => $this->createButtonsCollection([]),
                'expectedActions' => [],
                'groups' => ['group1'],
            ],
            '2 allowed actions' => [
                'config' => DatagridConfiguration::create(['name' => 'datagrid_name']),
                'record' => new ResultRecord(['id' => 2]),
                'buttonCollection' => $this->createButtonsCollection(
                    [
                        $this->createButton('operation1', true),
                        $this->createButton('operation2', true)
                    ]
                ),
                'expectedActions' => [
                    'action1' => ['option1' => 'value1', 'option2' => 'value2', 'key1' => 'value1'],
                    'action2' => ['option1' => 'value1', 'option2' => 'value2', 'key1' => 'value1'],
                ],
            ],
            '1 allowed action' => [
                'config' => DatagridConfiguration::create(['name' => 'datagrid_name']),
                'record' => new ResultRecord(['id' => 3]),
                'buttonCollection' => $this->createButtonsCollection(
                    [
                        $this->createButton('operation1', true),
                        $this->createButton('operation3', false)
                    ]
                ),
                'expectedActions' => [
                    'action1' => ['option1' => 'value1', 'option2' => 'value2', 'key1' => 'value1'],
                    'action3' => false
                ],
            ],
            '1 allowed action and array parent config' => [
                'config' => DatagridConfiguration::create([
                    'name' => 'datagrid_name',
                    ActionExtension::ACTION_CONFIGURATION_KEY => [
                        'view' => ['key1' => 'value1'],
                        'update' => false,
                    ],
                    'source' => [
                        'type' => OrmDatasource::TYPE,
                    ],
                ]),
                'record' => new ResultRecord(['id' => 4]),
                'buttonCollection' => $this->createButtonsCollection(
                    [
                        $this->createButton('action1', true, ['order' => 1]),
                        $this->createButton('action3', false, ['order' => 2])
                    ]
                ),
                'expectedActions' => [
                    'action1' => ['option1' => 'value1', 'option2' => 'value2', 'key1' => 'value1'],
                    'action3' => false,
                    'view' => ['key1' => 'value1'],
                    'update' => false,
                ],
            ],
            '1 allowed action and callable parent config' => [
                'config' => DatagridConfiguration::create([
                    'name' => 'datagrid_name',
                    ActionExtension::ACTION_CONFIGURATION_KEY => function () {
                        return [
                            'view' => ['key2' => 'value2'],
                            'update' => true,
                        ];
                    },
                    'source' => [
                        'type' => OrmDatasource::TYPE,
                    ],
                ]),
                'record' => new ResultRecord(['id' => 4]),
                'buttonCollection' => $this->createButtonsCollection(
                    [
                        $this->createButton('action1', true, ['order' => 1]),
                        $this->createButton('action3', false, ['order' => 2])
                    ]
                ),
                'expectedActions' => [
                    'action1' => ['option1' => 'value1', 'option2' => 'value2', 'key1' => 'value1'],
                    'action3' => false,
                    'view' => ['key2' => 'value2'],
                    'update' => true
                ],
            ]
        ];
    }

    /**
     * @param string $label
     * @param array $data
     * @return array
     */
    protected function getRowActionConfig($label = null, array $data = [])
    {
        return array_merge([
            'type' => 'button-widget',
            'label' => $label,
            'rowAction' => false,
            'link' => '#',
            'icon' => 'pencil-square-o',
        ], $data);
    }

    /**
     * @param string $name
     * @param bool $isAvailable
     * @param array $extraData
     *
     * @return ButtonInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected function createButton($name, $isAvailable = true, array $extraData = [])
    {
        $buttonContext = new ButtonContext();
        $buttonContext->setEnabled($isAvailable);

        return new StubButton(
            array_merge(
                [
                    'name' => $name,
                    'templateData' => ['additionalData' => []],
                    'buttonContext' => $buttonContext
                ],
                $extraData
            )
        );
    }

    /**
     * @param string $name
     * @param bool $isAvailable
     * @param array $datagridOptions
     * @param string $label
     * @return ButtonInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected function createOperationButton($name, $isAvailable, array $datagridOptions = [], $label = null)
    {
        $buttonContext = new ButtonContext();
        $buttonContext->setEnabled($isAvailable);

        return new OperationButton(
            $name,
            $this->createOperation($datagridOptions, $label),
            $buttonContext,
            new ActionData()
        );
    }

    /**
     * @param array $buttons
     * @return ButtonsCollection
     */
    protected function createButtonsCollection(array $buttons)
    {
        $extension = $this->createMock(ButtonProviderExtensionInterface::class);
        $extension->expects($this->once())->method('find')->willReturn($buttons);
        $extension->expects($this->any())
            ->method('isAvailable')
            ->willReturnCallback(
                function (ButtonInterface $button) {
                    return $button->getButtonContext()->isEnabled();
                }
            );

        $collection = new ButtonsCollection();
        $collection->consume($extension, new ButtonSearchContext());

        return $collection;
    }

    /**
     * @param array $datagridOptions
     * @param string $label
     * @return Operation|\PHPUnit_Framework_MockObject_MockObject
     */
    protected function createOperation(array $datagridOptions, $label = null)
    {
        $definition = $this->getMockBuilder(OperationDefinition::class)->disableOriginalConstructor()->getMock();
        $definition->expects($this->any())->method('getLabel')->willReturn($label);
        $definition->expects($this->any())->method('getDatagridOptions')->willReturn($datagridOptions);

        $operation = $this->getMockBuilder(Operation::class)->disableOriginalConstructor()->getMock();
        $operation->expects($this->any())->method('getDefinition')->willReturn($definition);

        return $operation;
    }
}
