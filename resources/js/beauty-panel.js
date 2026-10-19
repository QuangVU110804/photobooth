(function(root){
  'use strict';
  const labels={smooth:'Làm mịn',texture:'Giữ kết cấu da',even:'Đều màu da',radius:'Phạm vi làm mịn',sharpen:'Độ chi tiết',
    slim:'Thon mặt',chin:'Độ dài cằm',eyes:'Kích thước mắt',nose:'Thu gọn mũi',lips:'Kích thước môi',
    eyeBright:'Trắng mắt và răng',underEye:'Nâng sáng dưới mắt',lipAmount:'Màu môi',blushAmount:'Má hồng',
    bright:'Độ sáng',warmth:'Ấm / lạnh',vibrance:'Độ rực màu',contrast:'Tương phản',glow:'Ánh sáng mềm',vignette:'Tối góc',grain:'Hạt ảnh'};
  const titles={skin:'Da',shape:'Hình dáng khuôn mặt',makeup:'Mắt, môi và má',light:'Ánh sáng và màu'};
  class Panel {
    constructor(container,onChange) {
      this.container=container;this.onChange=onChange;this.state=null;
      const B=root.HmuBeautySettings,presets=document.createElement('div');presets.className='hmu-beauty-presets';
      presets.setAttribute('role','group');presets.setAttribute('aria-label','Mẫu làm đẹp');
      for(const preset of B.PRESETS) {
        const button=document.createElement('button');button.type='button';button.dataset.beautyPreset=preset.id;
        button.textContent=preset.name;button.addEventListener('click',()=>onChange(B.preset(preset.id)));presets.append(button);
      }
      container.append(presets);
      for(const group of B.GROUPS) {
        const section=document.createElement('details');section.open=group.id==='skin';section.className='hmu-beauty-section';
        const title=document.createElement('summary');title.textContent=titles[group.id];section.append(title);
        for(const c of group.controls) {
          const row=document.createElement('div');row.className='hmu-beauty-control';
          const label=document.createElement('label');label.htmlFor='hmu-beauty-'+c.key;label.textContent=labels[c.key];
          const out=document.createElement('output');out.htmlFor=label.htmlFor;out.id=label.htmlFor+'-value';
          const input=document.createElement('input');input.type='range';input.id=label.htmlFor;input.dataset.beauty=c.key;
          input.min=c.min;input.max=c.max;input.step=1;input.value=c.def;input.disabled=true;
          input.addEventListener('input',()=>{
            if(!this.state)return;const next=B.validate(this.state);next.values[c.key]=Number(input.value);next.preset='custom';onChange(next);
          });
          row.append(label,out,input);section.append(row);
          if(c.swatch) {
            const palette=document.createElement('div');palette.className='hmu-beauty-swatches';palette.setAttribute('role','group');palette.setAttribute('aria-label',labels[c.key]);
            for(const color of B.SWATCHES[c.swatch]) {
              const swatch=document.createElement('button');swatch.type='button';swatch.style.backgroundColor=color.hex;
              swatch.dataset.beautySwatch=c.swatch;swatch.dataset.hex=color.hex;swatch.setAttribute('aria-label',labels[c.key]+': '+color.name);swatch.title=color.name;
              swatch.addEventListener('click',()=>{const next=B.validate(this.state);next.colors[c.swatch]=color.hex;next.preset='custom';onChange(next);});palette.append(swatch);
            }
            section.append(palette);
          }
        }
        container.append(section);
      }
    }
    render(state,disabled) {
      this.state=state;
      for(const input of this.container.querySelectorAll('[data-beauty]')) {
        input.disabled=disabled;input.value=state.values[input.dataset.beauty];
        const value=Number(input.value);document.getElementById(input.id+'-value').textContent=(value>0&&Number(input.min)<0?'+':'')+value;
      }
      for(const button of this.container.querySelectorAll('button')) {
        button.disabled=disabled;
        button.setAttribute('aria-pressed',String(button.dataset.beautyPreset?button.dataset.beautyPreset===state.preset:button.dataset.hex===state.colors[button.dataset.beautySwatch]));
      }
    }
  }
  root.HmuBeautyPanel=Panel;
})(globalThis);
