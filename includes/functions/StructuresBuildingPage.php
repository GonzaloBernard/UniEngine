<?php

use UniEngine\Engine\Modules\Development;
use UniEngine\Engine\Includes\Helpers\World\Elements;
use UniEngine\Engine\Includes\Helpers\World\Resources;
use UniEngine\Engine\Modules\Development\Components\ModernQueue;
use UniEngine\Engine\Includes\Helpers\Planets;
use UniEngine\Engine\Includes\Helpers\Users;

function StructuresBuildingPage(&$CurrentPlanet, $CurrentUser)
{
    global $_Lang, $_SkinPath, $_GET, $_EnginePath, $_Vars_ElementCategories;

    include($_EnginePath.'includes/functions/GetElementTechReq.php');
    includeLang('worldElements.detailed');

    $Now = time();
    $Parse = &$_Lang;
    $ShowElementID = 0;

    PlanetResourceUpdate($CurrentUser, $CurrentPlanet, $Now);

    // Constants
    $ElementsPerRow = 7;

    // Get Templates
    $TPL['list_element']        = gettemplate('buildings_compact_list_element_structures');
    $TPL['list_levelmodif']     = gettemplate('buildings_compact_list_levelmodif');
    $TPL['list_hidden']         = gettemplate('buildings_compact_list_hidden');
    $TPL['list_row']            = gettemplate('buildings_compact_list_row');
    $TPL['list_breakrow']       = gettemplate('buildings_compact_list_breakrow');
    $TPL['list_disabled']       = gettemplate('buildings_compact_list_disabled');
    $TPL['list_partdisabled']   = parsetemplate($TPL['list_disabled'], array('AddOpacity' => 'dPart'));
    $TPL['list_disabled']       = parsetemplate($TPL['list_disabled'], array('AddOpacity' => ''));

    // Handle Commands
    $cmdResult = Development\Input\UserCommands\handleStructureCommand(
        $CurrentUser,
        $CurrentPlanet,
        $_GET,
        [
            "timestamp" => $Now
        ]
    );

    if ($cmdResult['isSuccess']) {
        $ShowElementID = $cmdResult['payload']['elementID'];
    }
    // End of - Handle Commands
    $structuresQueueContent = Planets\Queues\Structures\parseQueueString(
        $CurrentPlanet['buildQueue']
    );

    $queueComponent = ModernQueue\render([
        'planet' => &$CurrentPlanet,
        'queue' => $structuresQueueContent,
        'queueMaxLength' => Users\getMaxStructuresQueueLength($CurrentUser),
        'timestamp' => $Now,
        'infoComponents' => [],

        'getQueueElementCancellationLinkHref' => function ($queueElement) {
            $queueElementIdx = $queueElement['queueElementIdx'];
            $listID = $queueElement['listID'];
            $isFirstQueueElement = ($queueElementIdx === 0);
            $cmd = ($isFirstQueueElement ? "cancel" : "remove");

            return buildHref([
                'path' => 'buildings.php',
                'query' => [
                    'cmd' => $cmd,
                    'listid' => $listID
                ]
            ]);
        }
    ]);

    $queueStateDetails = Development\Utils\getQueueStateDetails([
        'queue' => [
            'type' => Development\Utils\QueueType::Planetary,
            'content' => $structuresQueueContent,
        ],
        'user' => $CurrentUser,
        'planet' => $CurrentPlanet,
    ]);
    $elementsInQueue = $queueStateDetails['queuedElementsCount'];
    $planetFieldsUsageCounter = 0;

    foreach ($queueStateDetails['queuedResourcesToUse'] as $resourceKey => $resourceValue) {
        if (Resources\isPlanetaryResource($resourceKey)) {
            $CurrentPlanet[$resourceKey] -= $resourceValue;
        } else if (Resources\isUserResource($resourceKey)) {
            $CurrentUser[$resourceKey] -= $resourceValue;
        }
    }
    foreach ($queueStateDetails['queuedElementLevelModifiers'] as $elementID => $elementLevelModifier) {
        $elementKey = Elements\getElementKey($elementID);
        $CurrentPlanet[$elementKey] += $elementLevelModifier;
        $planetFieldsUsageCounter += $elementLevelModifier;
    }

    $Parse['Create_Queue'] = $queueComponent['componentHTML'];

    // Parse all available buildings
    $hasAvailableFieldsOnPlanet = (
        ($CurrentPlanet['field_current'] + $planetFieldsUsageCounter) <
        CalculateMaxPlanetFields($CurrentPlanet)
    );
    $isQueueFull = (
        $elementsInQueue >=
        Users\getMaxStructuresQueueLength($CurrentUser)
    );
    $hasElementsInQueue = ($elementsInQueue > 0);
    $isUserOnVacation = isOnVacation($CurrentUser);

    $resourceLabels = [
        'metal'         => $_Lang['Metal'],
        'crystal'       => $_Lang['Crystal'],
        'deuterium'     => $_Lang['Deuterium'],
        'energy'        => $_Lang['Energy'],
        'energy_max'    => $_Lang['Energy'],
        'darkEnergy'    => $_Lang['DarkEnergy']
    ];

    $elementsDestructionDetails = [];

    foreach($_Vars_ElementCategories['build'] as $ElementID)
    {
        if(in_array($ElementID, $_Vars_ElementCategories['buildOn'][$CurrentPlanet['planet_type']]))
        {
            $ElementParser = [
                'SkinPath' => $_SkinPath,
            ];

            $elementQueuedLevel = Elements\getElementState($ElementID, $CurrentPlanet, $CurrentUser)['level'];
            $isElementInQueue = isset(
                $queueStateDetails['queuedElementLevelModifiers'][$ElementID]
            );
            $elementQueueLevelModifier = (
                $isElementInQueue ?
                $queueStateDetails['queuedElementLevelModifiers'][$ElementID] :
                0
            );
            $elementCurrentLevel = (
                $elementQueuedLevel +
                ($elementQueueLevelModifier * -1)
            );

            $elementMaxLevel = Elements\getElementMaxUpgradeLevel($ElementID);
            $hasReachedMaxLevel = (
                $elementQueuedLevel >=
                $elementMaxLevel
            );

            $hasTechnologyRequirementMet = IsTechnologieAccessible($CurrentUser, $CurrentPlanet, $ElementID);
            $hasUpgradeResources = IsElementBuyable($CurrentUser, $CurrentPlanet, $ElementID, false);

            $isBlockedByTechResearchProgress = (
                $ElementID == 31 &&
                $CurrentUser['techQueue_Planet'] > 0 &&
                $CurrentUser['techQueue_EndTime'] > 0 &&
                !isLabUpgradableWhileInUse()
            );

            $hasDowngradeResources = IsElementBuyable(
                $CurrentUser,
                $CurrentPlanet,
                $ElementID,
                true
            );

            $isUpgradePossible = (!$hasReachedMaxLevel);
            $isUpgradeQueueable = (
                $isUpgradePossible &&
                !$$isUserOnVacation &&
                !$isQueueFull &&
                $hasAvailableFieldsOnPlanet &&
                $hasTechnologyRequirementMet &&
                !$isBlockedByTechResearchProgress
            );
            $isUpgradeAvailableNow = (
                $isUpgradeQueueable &&
                $hasUpgradeResources
            );

            $isDowngradePossible = (
                ($elementQueuedLevel > 0) &&
                !Elements\isIndestructibleStructure($ElementID)
            );
            $isDowngradeQueueable = (
                $isDowngradePossible &&
                !$$isUserOnVacation &&
                !$isQueueFull &&
                !$isBlockedByTechResearchProgress
            );
            $isDowngradeAvailableNow = (
                $isDowngradeQueueable &&
                $hasDowngradeResources
            );

            $ElementParser['ElementName'] = $_Lang['tech'][$ElementID];
            $ElementParser['ElementID'] = $ElementID;
            $ElementParser['ElementRealLevel'] = prettyNumber($elementCurrentLevel);
            $ElementParser['BuildButtonColor'] = 'buildDo_Green';

            if($isElementInQueue) {
                $ElementParser['ElementLevelModif'] = parsetemplate(
                    $TPL['list_levelmodif'],
                    [
                        'modColor' => classNames([
                            'red' => ($elementQueueLevelModifier < 0),
                            'orange' => ($elementQueueLevelModifier == 0),
                            'lime' => ($elementQueueLevelModifier > 0),
                        ]),
                        'modText' => (
                            ($elementQueueLevelModifier > 0 ? '+' : '') .
                            prettyNumber($elementQueueLevelModifier)
                        ),
                    ]
                );
            }

            if(!$hasUpgradeResources)
            {
                if($elementsInQueue == 0)
                {
                    $ElementParser['BuildButtonColor'] = 'buildDo_Gray';
                }
                else
                {
                    $ElementParser['BuildButtonColor'] = 'buildDo_Orange';
                }
            }

            $BlockReason = array();

            if ($hasReachedMaxLevel) {
                $BlockReason[] = $_Lang['ListBox_Disallow_MaxLevelReached'];
            }
            else if (!$hasUpgradeResources) {
                $BlockReason[] = $_Lang['ListBox_Disallow_NoResources'];
            }
            if (!$hasTechnologyRequirementMet) {
                $BlockReason[] = $_Lang['ListBox_Disallow_NoTech'];
                $ElementParser['BuildButtonColor'] = 'buildDo_Gray';
            }
            if ($isBlockedByTechResearchProgress) {
                $BlockReason[] = $_Lang['ListBox_Disallow_LabResearch'];
                $ElementParser['BuildButtonColor'] = 'buildDo_Gray';
            }
            if (!$hasAvailableFieldsOnPlanet) {
                $BlockReason[] = $_Lang['ListBox_Disallow_NoFreeFields'];
                $ElementParser['BuildButtonColor'] = 'buildDo_Gray';
            }
            if ($isQueueFull) {
                $BlockReason[] = $_Lang['ListBox_Disallow_QueueIsFull'];
                $ElementParser['BuildButtonColor'] = 'buildDo_Gray';
            }
            if ($isUserOnVacation) {
                $BlockReason[] = $_Lang['ListBox_Disallow_VacationMode'];
                $ElementParser['BuildButtonColor'] = 'buildDo_Gray';
            }

            if(!empty($BlockReason))
            {
                $ElementParser['ElementDisabled'] = (
                    $isUpgradeQueueable ?
                    $TPL['list_partdisabled'] :
                    $TPL['list_disabled']
                );
                $ElementParser['ElementDisableReason'] = end($BlockReason);
            }

            if ($isDowngradePossible) {
                $downgradeCost = Elements\calculatePurchaseCost(
                    $ElementID,
                    Elements\getElementState($ElementID, $CurrentPlanet, $CurrentUser),
                    [
                        'purchaseMode' => Elements\PurchaseMode::Downgrade
                    ]
                );

                $elementDowngradeResources = [];

                foreach ($downgradeCost as $costResourceKey => $costValue) {
                    $currentResourceState = Resources\getResourceState(
                        $costResourceKey,
                        $CurrentUser,
                        $CurrentPlanet
                    );

                    $resourceLeft = ($currentResourceState - $costValue);
                    $hasResourceDeficit = ($resourceLeft < 0);

                    $resourceCostColor = (
                        !$hasResourceDeficit ?
                        '' :
                        (
                            $hasElementsInQueue ?
                            'orange' :
                            'red'
                        )
                    );

                    $elementDowngradeResources[] = [
                        'name' => $resourceLabels[$costResourceKey],
                        'color' => $resourceCostColor,
                        'value' => prettyNumber($costValue)
                    ];
                }

                $destructionTime = GetBuildingTime($CurrentUser, $CurrentPlanet, $ElementID) / 2;

                $elementsDestructionDetails[$ElementID] = [
                    'resources' => $elementDowngradeResources,
                    'destructionTime' => pretty_time($destructionTime)
                ];
            }

            if (
                !$isUpgradeQueueable ||
                (!$hasUpgradeResources && !$hasElementsInQueue)
            ) {
                $ElementParser['HideQuickBuildButton'] = 'hide';
            }

            $ElementParser['BuildButtonColor'] = classNames([
                'buildDo_Green' => $isUpgradeAvailableNow,
                'buildDo_Orange' => (!$isUpgradeAvailableNow && $isUpgradeQueueable),
            ]);

            $StructuresList[] = parsetemplate($TPL['list_element'], $ElementParser);

            $cardInfoComponent = Development\Components\GridViewElementCard\render([
                'elementID' => $ElementID,
                'user' => $CurrentUser,
                'planet' => $CurrentPlanet,
                'isQueueActive' => $hasElementsInQueue,
                'elementDetails' => [
                    'currentState' => $elementCurrentLevel,
                    'isInQueue' => $isElementInQueue,
                    'queueLevelModifier' => $elementQueueLevelModifier,
                    'isUpgradePossible' => $isUpgradePossible,
                    'isUpgradeAvailable' => $isUpgradeAvailableNow,
                    'isUpgradeQueueable' => $isUpgradeQueueable,
                    'whyUpgradeImpossible' => [
                        (
                            $hasReachedMaxLevel ?
                            $_Lang['ListBox_Disallow_MaxLevelReached'] :
                            ''
                        ),
                    ],
                    'isDowngradePossible' => $isDowngradePossible,
                    'isDowngradeAvailable' => $isDowngradeAvailableNow,
                    'isDowngradeQueueable' => $isDowngradeQueueable,
                    'hasTechnologyRequirementMet' => $hasTechnologyRequirementMet,
                    'additionalUpgradeDetailsRows' => [
                        (
                            in_array($ElementID, $_Vars_ElementCategories['prod']) ?
                            Development\Components\GridViewElementCard\UpgradeProductionChange\render([
                                'elementID' => $ElementID,
                                'user' => $CurrentUser,
                                'planet' => $CurrentPlanet,
                                'timestamp' => $Now,
                                'elementDetails' => [
                                    'currentState' => $elementCurrentLevel,
                                    'queueLevelModifier' => $elementQueueLevelModifier,
                                ],
                            ])['componentHTML'] :
                            ''
                        ),
                    ],
                ],
                'getUpgradeElementActionLinkHref' => function () use ($ElementID) {
                    return "?cmd=insert&amp;building={$ElementID}";
                },
                'getDowngradeElementActionLinkHref' => function () use ($ElementID) {
                    return "?cmd=destroy&amp;building={$ElementID}";
                },
            ]);

            $InfoBoxes[] = $cardInfoComponent['componentHTML'];
        }
    }

    foreach ($queueStateDetails['queuedResourcesToUse'] as $resourceKey => $resourceValue) {
        if (Resources\isPlanetaryResource($resourceKey)) {
            $CurrentPlanet[$resourceKey] += $resourceValue;
        } else if (Resources\isUserResource($resourceKey)) {
            $CurrentUser[$resourceKey] += $resourceValue;
        }
    }
    foreach ($queueStateDetails['queuedElementLevelModifiers'] as $elementID => $elementLevelModifier) {
        $elementKey = Elements\getElementKey($elementID);
        $CurrentPlanet[$elementKey] -= $elementLevelModifier;
    }

    // Create Structures List
    $ThisRowIndex = 0;
    $InRowCount = 0;
    foreach($StructuresList as $ParsedData)
    {
        if($InRowCount == $ElementsPerRow)
        {
            $ParsedRows[($ThisRowIndex + 1)] = $TPL['list_breakrow'];
            $ThisRowIndex += 2;
            $InRowCount = 0;
        }

        if(!isset($StructureRows[$ThisRowIndex]['Elements']))
        {
            $StructureRows[$ThisRowIndex]['Elements'] = '';
        }
        $StructureRows[$ThisRowIndex]['Elements'] .= $ParsedData;
        $InRowCount += 1;
    }
    if($InRowCount < $ElementsPerRow)
    {
        if(!isset($StructureRows[$ThisRowIndex]['Elements']))
        {
            $StructureRows[$ThisRowIndex]['Elements'] = '';
        }
        $StructureRows[$ThisRowIndex]['Elements'] .= str_repeat($TPL['list_hidden'], ($ElementsPerRow - $InRowCount));
    }
    foreach($StructureRows as $Index => $Data)
    {
        $ParsedRows[$Index] = parsetemplate($TPL['list_row'], $Data);
    }
    ksort($ParsedRows, SORT_ASC);
    $Parse['Create_StructuresList'] = implode('', $ParsedRows);
    $Parse['Create_ElementsInfoBoxes'] = implode('', $InfoBoxes);
    if($ShowElementID > 0)
    {
        $Parse['Create_ShowElementOnStartup'] = $ShowElementID;
    }
    $MaxFields = CalculateMaxPlanetFields($CurrentPlanet);
    if($CurrentPlanet['field_current'] == $MaxFields)
    {
        $Parse['Insert_Overview_Fields_Used_Color'] = 'red';
    }
    else if($CurrentPlanet['field_current'] >= ($MaxFields * 0.9))
    {
        $Parse['Insert_Overview_Fields_Used_Color'] = 'orange';
    }
    else
    {
        $Parse['Insert_Overview_Fields_Used_Color'] = 'lime';
    }
    // End of - Parse all available buildings

    $Parse['Insert_SkinPath'] = $_SkinPath;
    $Parse['Insert_PlanetImg'] = $CurrentPlanet['image'];
    $Parse['Insert_PlanetType'] = $_Lang['PlanetType_'.$CurrentPlanet['planet_type']];
    $Parse['Insert_PlanetName'] = $CurrentPlanet['name'];
    $Parse['Insert_PlanetPos_Galaxy'] = $CurrentPlanet['galaxy'];
    $Parse['Insert_PlanetPos_System'] = $CurrentPlanet['system'];
    $Parse['Insert_PlanetPos_Planet'] = $CurrentPlanet['planet'];
    $Parse['Insert_Overview_Diameter'] = prettyNumber($CurrentPlanet['diameter']);
    $Parse['Insert_Overview_Fields_Used'] = prettyNumber($CurrentPlanet['field_current']);
    $Parse['Insert_Overview_Fields_Max'] = prettyNumber($MaxFields);
    $Parse['Insert_Overview_Fields_Percent'] = sprintf('%0.2f', ($CurrentPlanet['field_current'] / $MaxFields) * 100);
    $Parse['Insert_Overview_Temperature'] = sprintf($_Lang['Overview_Form_Temperature'], $CurrentPlanet['temp_min'], $CurrentPlanet['temp_max']);
    $Parse['PHPData_ElementsDestructionDetailsJSON'] = json_encode($elementsDestructionDetails);

    $Page = parsetemplate(gettemplate('buildings_compact_body_structures'), $Parse);

    display($Page, $_Lang['Builds']);
}

?>
