
export default
{
	inject: [ 'all_schemas' ],

	props: {
		pointer: { default: "", type: String },
		local_pointer: { default: "", type: String },
		validations: { default: null, type: Object },
	},

	methods:{
		//loadSchemas() { if (this.schemas == null) this.$store.dispatch("ce/getSchemas", { }); },

		flatSchemas(schema, currentRef)
		{
			if (schema.$ref !== undefined)
			{
				return this.flatSchemas(this.getSchemaOfRef(schema.$ref, currentRef));
			}

			let list = schema.anyOf !== undefined ? schema.anyOf : schema.allOf;
			if (list !== undefined)
			{
				let res = [];
				for (let i in list) res = res.concat( this.flatSchemas(list[i]) );
				return res;
			}

			return [ schema ];
		},

		getDefaultObjectsFor(schema, currentRef, uiProps = [])
		{
			// UI props
			let currentUiProps = {};
			for (let prop in schema)
			{
				if (prop.indexOf('_ui_') === 0) currentUiProps[prop] = schema[prop];
			}

			if (schema.const !== undefined)
			{
				uiProps.push(currentUiProps);
				return [ schema.const ];
			}

			if (schema.type == 'string')
			{
				uiProps.push(currentUiProps);
				return [ schema.enum != undefined ? schema.enum[0] : "" ];
			}

			if (schema.type == 'object')
			{
				let obj = {};
				if (schema.required != undefined) for(let i in schema.required)
				{
					const prop = schema.required[i];
					obj[prop] = this.getDefaultObjectsFor(schema.properties[prop], currentRef)[0];
				}

				uiProps.push(currentUiProps);
				return [ obj ];
			}

			if (schema.type == 'array')
			{
				let array = [];
				if (schema.minItems != undefined)
				{
					for (let i = 0; i < schema.minItems; i++)
					{
						array.push(this.getDefaultObjectsFor(schema.items, currentRef));
					}
				}

				uiProps.push(currentUiProps);
				return [ array ];
			}

			if (schema.type == 'boolean')
			{
				uiProps.push(currentUiProps);
				return [ false ];
			}

			if (schema.type == 'null')
			{
				uiProps.push(currentUiProps);
				return [ null ];
			}

			if (schema.type == 'integer')
			{
				if (schema.enum != undefined) return [ schema.enum[0] ];

				uiProps.push(currentUiProps);

				return [ schema.minimum != undefined
					? schema.minimum
					: 0
				];
			}

			if (schema['$ref'] !== undefined)
			{

				let refSchema = this.getSchemaOfRef(schema['$ref'], currentRef);

				let res = this.getDefaultObjectsFor(refSchema, currentRef, uiProps);

				// override ui props
				for (let prop in currentUiProps)
				{
					uiProps[uiProps.length -1][prop] = currentUiProps[prop];
				}

				return res;
			}

			if (schema['allOf'] !== undefined)
			{
				return this.getDefaultObjectsFor(schema['allOf'][0], currentRef, uiProps);
			}

			if (schema['anyOf'] !== undefined)
			{
				let res = [];
				for(let i in schema['anyOf'])
				{
					res = res.concat(this.getDefaultObjectsFor(schema['anyOf'][i], currentRef, uiProps));
				}

				return res;
			}

			console.error("Schema non géré", schema);
			throw new Error("Schema non géré");
		},

		getSchemaOfRef(ref, currentRefSchema)
		{
			if (ref == "#") return currentRefSchema;
			//if (ref[0] == "/") ref = ref.substr(1);
			return this.schemas[ref];
		},
	},

	computed: {
		currentValidations()
		{
			if (this.pointer === null || this.validations === null) return [];
			if (this.validations[this.pointer] === undefined) return [];

			return this.validations[this.pointer];
		},

		
		// TODO 
		isDebug() { return false; },


		schemas() { return this.all_schemas; },

	},
}
