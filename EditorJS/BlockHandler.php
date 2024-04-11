<?php

namespace EditorJS;

/**
 * Class BlockHandler
 *
 * @package EditorJS
 */
class BlockHandler
{
    /**
     * Default pseudo-key for numerical arrays
     */
    const DEFAULT_ARRAY_KEY = "-";

    /**
     * @var ConfigLoader|null
     */
    private $rules = null;

    /**
     * BlockHandler constructor
     *
     * @param string $configuration
     *
     * @throws EditorJSException
     */
    public function __construct($configuration)
    {
        $this->rules = new ConfigLoader($configuration);
    }

    /**
     * Validate block for correctness
     *
     * @param string $blockType
     * @param array  $blockData
     *
     * @throws EditorJSException
     *
     * @return bool
     */
    public function validateBlock($blockType, $blockData)
    {
        /**
         * Default action for blocks that are not mentioned in a configuration
         */
        if (!array_key_exists($blockType, $this->rules->tools)) {
            throw new EditorJSException("Tool `$blockType` not found in the configuration");
        }

        $rule = $this->rules->tools[$blockType];

        return $this->validate($rule, $blockData);
    }

    /**
     * Apply sanitizing rules according to the block type
     *
     * @param string $blockType
     * @param array  $blockData
     *
     * @throws EditorJSException
     *
     * @return array|bool
     */
    public function sanitizeBlock($blockType, $blockData, $blockTunes)
    {
        $rule = $this->rules->tools[$blockType];

        return [
            'type' => $blockType,
            'data' => $this->sanitize($rule, $blockData),
            'tunes' => $blockTunes
        ];
    }

    /**
     * Apply validation rule to the data block
     *
     * @param array $rules
     * @param array $blockData
     *
     * @throws EditorJSException
     *
     * @return bool
     */
    private function validate($rules, $blockData)
    {
        /**
         * Make sure that every required param exists in data block
         */
        foreach ($rules as $key => $value) {
            if (($key != BlockHandler::DEFAULT_ARRAY_KEY) && (isset($value['required']) ? $value['required'] : true)) {
                if (!isset($blockData[$key])) {
                    throw new EditorJSException("Not found required param `$key`");
                }
            }
        }

        /**
         * Check if there is not extra params (not mentioned in configuration rule)
         */
        foreach ($blockData as $key => $value) {
            if (!is_integer($key) && !isset($rules[$key])) {
                throw new EditorJSException("Found extra param `$key`");
            }
        }

        /**
         * Validate every key in data block
         */
        foreach ($blockData as $key => $value) {
            /**
             * PHP Array has integer keys
             */
            if (is_integer($key)) {
                $key = BlockHandler::DEFAULT_ARRAY_KEY;
            }

            $rule = $rules[$key];

            $rule = $this->expandToolSettings($rule);

            $elementType = $rule['type'];

            /**
             * Process canBeOnly rule
             */
            if (isset($rule['canBeOnly'])) {
                if (!in_array($value, $rule['canBeOnly'])) {
                    throw new EditorJSException("Option '$key' with value `$value` has invalid value. Check canBeOnly param.");
                }

                // Do not perform additional elements validation in any case
                continue;
            }

            /**
             * Do not check element type if it is not required and null
             */
            if (isset($rule['required']) && $rule['required'] === false &&
                isset($rule['allow_null']) && $rule['allow_null'] === true && $value === null) {
                continue;
            }

            /**
             * Validate element types
             */
            switch ($elementType) {
                case 'string':
                    if (!is_string($value)) {
                        throw new EditorJSException("Option '$key' with value `$value` must be string");
                    }
                    break;

                case 'integer':
                case 'int':
                    if (!is_integer($value)) {
                        throw new EditorJSException("Option '$key' with value `$value` must be integer");
                    }
                    break;

                case 'array':
                    $this->validate($rule['data'], $value);
                    break;

                case 'boolean':
                case 'bool':
                    if (!is_bool($value)) {
                        throw new EditorJSException("Option '$key' with value `$value` must be boolean");
                    }
                    break;

                default:
                    throw new EditorJSException("Unhandled type `$elementType`");
            }
        }

        return true;
    }

    /**
     * Sanitize strings in the data block
     *
     * @param array $rules
     * @param array $blockData
     *
     * @throws EditorJSException
     *
     * @return array
     */
    private function sanitize($rules, $blockData)
    {
        /**
         * Sanitize every key in data block
         */
        foreach ($blockData as $key => $value) {
            /**
             * PHP Array has integer keys
             */
            if (is_integer($key)) {
                $rule = $rules[BlockHandler::DEFAULT_ARRAY_KEY];
            } else {
                $rule = $rules[$key];
            }

            $rule = $this->expandToolSettings($rule);
            $elementType = $rule['type'];

            /**
             * Sanitize string with Purifier
             */
            if ($elementType == 'string') {
                $allowedTags = isset($rule['allowedTags']) ? $rule['allowedTags'] : '';
                if ($allowedTags !== '*') {
                    $blockData[$key] = $this->getPurifier($allowedTags)->purify($value);
                }
            }

            /**
             * Sanitize nested elements
             */
            if ($elementType == 'array') {
                $blockData[$key] = $this->sanitize($rule['data'], $value);
            }
        }

        return $blockData;
    }

    /**
     * Create and return new default purifier
     *
     * @param $allowedTags
     *
     * @return \HTMLPurifier
     */
    private function getPurifier($allowedTags)
    {
        $sanitizer = $this->getDefaultPurifier();

        $sanitizer->set('HTML.Allowed', $allowedTags);

        if ($def = $sanitizer->maybeGetRawHTMLDefinition()) {
            // modify the raw HTML definition
            $this->addCustomHTMLDefinitions($def);
        }

        $purifier = new \HTMLPurifier($sanitizer);

        return $purifier;
    }

    /**
     * TODO
     */
    private function addCustomHTMLDefinitions($def)
    {
        if($this->rules->customTags){

            // default to be added
            $def->addElement('mark', 'Inline', 'Inline', 'Common');

            foreach($this->rules->customTags as $tag => $tagData){
                /**
                 * Convenience function that sets up a new element
                 * @param string $element Name of element to add
                 * @param string|bool $type What content set should element be registered to?
                 *              Set as false to skip this step.
                 * @param string|\HTMLPurifier_ChildDef $contents Allowed children in form of:
                 *              "$content_model_type: $content_model"
                 * @param array|string $attr_includes What attribute collections to register to
                 *              element?
                 * @param array $attr What unique attributes does the element define?
                 * @see HTMLPurifier_ElementDef:: for in-depth descriptions of these parameters.
                 * @return \HTMLPurifier_ElementDef Created element definition object, so you
                 *         can set advanced parameters
                 */

                /**
                 * "customTags": {
                 *   "tagName1": {
                 *     "type": "string",
                 *     "contents": "string", (Empty, Inline, Flow)
                 *     "collection": "Common",
                 *     "attributes": {
                 *       "attrName1": "attrType",
                 *       "attrName2": { "type": "Enum", "options": "item1,item2,item3" | ["item","item","item"] }
                 *       "attrName2": { "type": "Number", "options": [bool, bool, bool] }
                 *     }
                 *   }
                 * }
                 */
                $def->addElement(
                    $tag, 
                    $tagData['type'] ?: 'Inline', 
                    $tagData['contents'] ?: 'Inline', 
                    $tagData['collection'] ?: 'Common'
                );
                
                // custom attributes
                foreach($tagData['attributes'] as $attrName => $attrData){

                    /**
                     * [{ name: '', attr: '', type: ''| {} }]
                     * Adds a custom attribute to a pre-existing element
                     * @note This is strictly convenience, and does not have a corresponding
                     *       method in HTMLPurifier_HTMLModule
                     * @param string $element_name Element name to add attribute to
                     * @param string $attr_name Name of attribute
                     * @param mixed $def Attribute definition, can be string or object, see
                     *             HTMLPurifier_AttrTypes for details \HTMLPurifier\HTMLPurifier_AttrTypes
                     */
                    if(is_string($attrData)){
                        // no config options given for attribute type (default)
                        // Not implemented: in case # is present, the user might have defined it according to HTMLPurifier docs: i.e. Enum#_blank,_self,_target,_top
                        $def->addAttribute($tag, $attrName, $attrData);
                    }
                    elseif(isset($attrData['type'])){
                        /** 
                         * note the types are case sensitive!
                         * @see \HTMLPurifier_AttrTypes::class for options
                         */
                        if($attrData['type'] == 'Enum'){
                            if(is_string($attrData['options'])){
                                // we assume that the options are comma separated
                                $options = explode(',', $attrData['options']);
                            } else{
                                $options = $attrData['options'];
                            }
                            $def->addAttribute($tag, $attrName, $attrData['type'], new \HTMLPurifier_AttrDef_Enum( $options ) );
                        }
                        if($attrData['type'] == 'Number'){
                            $def->addAttribute($tag, $attrName, $attrData['type'], new \HTMLPurifier_AttrDef_Integer( 
                                $attrData['options'][0] ?: true, // negative
                                $attrData['options'][1] ?: true, // zero
                                $attrData['options'][2] ?: true  // positive
                            ));
                        }
                    }
                }
            }
        }
    }

    /**
     * Initialize HTML Purifier with default settings
     */
    private function getDefaultPurifier()
    {
        $sanitizer = \HTMLPurifier_Config::createDefault();

        $sanitizer->set('HTML.TargetBlank', true);
        $sanitizer->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
        $sanitizer->set('AutoFormat.RemoveEmpty', true);
        $sanitizer->set('HTML.DefinitionID', 'html5-definitions');
        $sanitizer->set('HTML.DefinitionRev', time()); 

        $cacheDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'purifier';
        if (!is_dir($cacheDirectory)) {
            mkdir($cacheDirectory, 0777, true);
        }

        $sanitizer->set('Cache.SerializerPath', $cacheDirectory);

        return $sanitizer;
    }

    /**
     * Check whether the array is associative or sequential
     *
     * @param array $arr – array to check
     *
     * @return bool – true if the array is associative
     */
    private function isAssoc(array $arr)
    {
        if ([] === $arr) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * Expand shortified tool settings
     *
     * @param $rule – tool settings
     *
     * @throws EditorJSException
     *
     * @return array – expanded tool settings
     */
    private function expandToolSettings($rule)
    {
        if (is_string($rule)) {
            // 'blockName': 'string' – tool with string type and default settings
            $expandedRule = ["type" => $rule];
        } elseif (is_array($rule)) {
            if ($this->isAssoc($rule)) {
                $expandedRule = $rule;
            } else {
                // 'blockName': [] – tool with canBeOnly and default settings
                $expandedRule = ["type" => "string", "canBeOnly" => $rule];
            }
        } else {
            throw new EditorJSException("Cannot determine element type of the rule `$rule`.");
        }

        return $expandedRule;
    }
}
