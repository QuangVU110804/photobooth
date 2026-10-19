/* Shared reference GPU pipeline. CTBot000/face-beautifier ca5ad40, MIT. */
(function(root){
  'use strict';
  let engine;
  const api={
    indices(prefix=[33,263,93,323]) {return [...new Set([...prefix,...Array.from({length:478},(_,i)=>i)])];},
    async createRenderer(canvas,onLoss) {
      if(!engine)engine=import('./beauty/engine.mjs?v=1').catch(error=>{engine=null;throw error;});
      const {Engine}=await engine;return new Engine(canvas,onLoss);
    }
  };
  root.HmuSkinBeauty=api;
  if(typeof module==='object'&&module.exports)module.exports=api;
})(typeof globalThis==='object'?globalThis:this);
