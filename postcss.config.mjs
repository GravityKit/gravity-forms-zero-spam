import postcssSimpleVars from 'postcss-simple-vars';
import postcssNesting from 'postcss-nesting';
import cssnano from 'cssnano';

export default {
	plugins: [
		postcssSimpleVars,
		postcssNesting,
		cssnano,
	],
};
