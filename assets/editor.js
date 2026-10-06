/* global wp */
(() => {
  'use strict';
  const { registerBlockType } = wp.blocks;
  const { createElement } = wp.element;
  const { __ } = wp.i18n;
  const ServerSideRender = wp.serverSideRender;
  const blocks = {
    feed: __('Personalized feed', 'buddypress-intelligence'),
    people: __('People to meet', 'buddypress-intelligence'),
    groups: __('Recommended groups', 'buddypress-intelligence'),
    topics: __('Topics', 'buddypress-intelligence'),
    questions: __('Questions', 'buddypress-intelligence'),
    experts: __('Experts', 'buddypress-intelligence'),
    knowledge: __('Knowledge', 'buddypress-intelligence'),
    reputation: __('Reputation summary', 'buddypress-intelligence')
  };
  for (const [name, title] of Object.entries(blocks)) {
    registerBlockType(`buddypress-intelligence/${name}`, {
      apiVersion: 3, title, icon: 'groups', category: 'widgets',
      supports: { html: false },
      edit: () => createElement(ServerSideRender, { block: `buddypress-intelligence/${name}` }),
      save: () => null
    });
  }
})();
