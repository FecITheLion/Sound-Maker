<?php
declare(strict_types=1);

final class Noise implements JsonSerializable {
    public function __construct(public string $kind = 'white', public int $seed = 1) {}
    private float $b0=0,$b1=0,$b2=0,$b3=0,$b4=0,$b5=0,$b6=0,$brown=0,$prev=0,$prev2=0;
    public function __invoke(): float {
        $this->seed = (int)(($this->seed * 1103515245 + 12345) & 0x7fffffff);
        $w = ($this->seed / 0x7fffffff) * 2 - 1;
        return match ($this->kind) {
            'pink' => $this->pink($w),
            'brown' => $this->brown = ($this->brown + 0.02 * $w) / 1.02 * 3.5,
            'blue' => (($s = $w - $this->prev) * 0.5) + ($this->prev = $w) * 0,
            'violet' => (($s = $w - 2*$this->prev + $this->prev2) * 0.25) + ($this->prev2 = $this->prev)*0 + ($this->prev = $w)*0,
            'bit' => $w > 0 ? 1.0 : 0.0,
            'unit' => ($w + 1) / 2,
            default => $w,
        };
    }
    private function pink(float $w): float {
        $this->b0=0.99886*$this->b0+$w*0.0555179; $this->b1=0.99332*$this->b1+$w*0.0750759;
        $this->b2=0.96900*$this->b2+$w*0.1538520; $this->b3=0.86650*$this->b3+$w*0.3104856;
        $this->b4=0.55000*$this->b4+$w*0.5329522; $this->b5=-0.7616*$this->b5-$w*0.0168980;
        $p=$this->b0+$this->b1+$this->b2+$this->b3+$this->b4+$this->b5+$this->b6+$w*0.5362;
        $this->b6=$w*0.115926; return $p*0.11;
    }
    public function jsonSerialize(): array { return ['kind'=>$this->kind,'seed'=>$this->seed]; }
    public static function from(array $d): self { return new self($d['kind']??'white',$d['seed']??1); }
}

final class Param implements JsonSerializable {
    public function __construct(public array $points, public ?string $curve=null, public float $seed=0) {}
    public function current(): float { return (float)$this->points[intdiv(count($this->points),2)]; }
    public function at(float $time): float { return $this->curve ? Curve::eval($this->curve,$time,$this->current()) : $this->current(); }
    public function jsonSerialize(): array {
        return $this->curve ? ['seed'=>$this->seed,'curve'=>$this->curve]
            : (count($this->points)>1 ? ['range'=>$this->points] : ['value'=>$this->points[0]]);
    }
    public static function from(array $d): self {
        if (isset($d['curve'])) return new self([(float)$d['seed']], $d['curve'], (float)$d['seed']);
        if (isset($d['range'])) return new self(array_map('floatval',$d['range']));
        return new self([(float)$d['value']]);
    }
}

final class Curve {
    public static function eval(string $expr, float $time, float $freq): float {
        $p = new CurveParser(strtolower($expr), $time, $freq);
        $value = $p->parse();
        if (!$p->done()) throw new InvalidArgumentException("Bad curve $expr");
        return $value;
    }
}

final class CurveParser {
    private int $i = 0;
    public function __construct(private string $s, private float $time, private float $freq) {}
    public function done(): bool { $this->skip(); return $this->i >= strlen($this->s); }
    public function parse(): float { return $this->add(); }
    private function add(): float {
        $n = $this->mul();
        while ($this->take('+') || $this->take('-')) $n = $this->s[$this->i - 1] === '+' ? $n + $this->mul() : $n - $this->mul();
        return $n;
    }
    private function mul(): float {
        $n = $this->unary();
        while ($this->take('*') || $this->take('/')) $n = $this->s[$this->i - 1] === '*' ? $n * $this->unary() : $n / $this->unary();
        return $n;
    }
    private function unary(): float { return $this->take('-') ? -$this->atom() : $this->atom(); }
    private function atom(): float {
        $this->skip();
        if ($this->take('(')) { $n = $this->add(); if (!$this->take(')')) throw new InvalidArgumentException('Missing )'); return $n; }
        if ($this->i < strlen($this->s) && (ctype_digit($this->s[$this->i]) || $this->s[$this->i] === '.')) return $this->number();
        $name = $this->ident();
        if ($this->take('(')) {
            $args = [$this->add()];
            while ($this->take(',')) $args[] = $this->add();
            if (!$this->take(')')) throw new InvalidArgumentException('Missing )');
            return match ($name) {
                'min' => min($args[0], $args[1] ?? $args[0]),
                'max' => max($args[0], $args[1] ?? $args[0]),
                'pow' => $args[0] ** ($args[1] ?? 1),
                'sin' => sin($args[0]), 'cos' => cos($args[0]), 'exp' => exp($args[0]), 'abs' => abs($args[0]),
                default => throw new InvalidArgumentException("Unknown call $name"),
            };
        }
        return match ($name) { 'time' => $this->time, 'freq' => $this->freq, default => throw new InvalidArgumentException("Unknown name $name") };
    }
    private function number(): float {
        $start = $this->i;
        while ($this->i < strlen($this->s) && (ctype_digit($this->s[$this->i]) || $this->s[$this->i] === '.')) $this->i++;
        return (float)substr($this->s, $start, $this->i - $start);
    }
    private function ident(): string {
        $start = $this->i;
        while ($this->i < strlen($this->s) && ctype_alpha($this->s[$this->i])) $this->i++;
        if ($start === $this->i) throw new InvalidArgumentException('Expected a name');
        return substr($this->s, $start, $this->i - $start);
    }
    private function take(string $c): bool { $this->skip(); if ($this->i < strlen($this->s) && $this->s[$this->i] === $c) { $this->i++; return true; } return false; }
    private function skip(): void { while ($this->i < strlen($this->s) && ctype_space($this->s[$this->i])) $this->i++; }
}

final class Hit implements JsonSerializable {
    public function __construct(
        public string $name,
        public Noise $noise,
        public string $shape = 'sine',
        public array $order = ['click','noise','hp','tone','lp','drive'],
        public float $gap = 0,
        public float $dubRatio = 1,
        public float $attack = 80,
        public array $params = []
    ) {}
    public function render(int $rate = 44100): array {
        $n = max(1, (int)($rate * $this->params['duration']->current()));
        $out = array_fill(0, $n, 0.0);
        $this->voice($out, 0, 1, 1, $rate);
        if ($this->gap > 0) $this->voice($out, (int)($rate * $this->gap), $this->dubRatio, 0.75, $rate);
        $max = max(array_map('abs', $out)) ?: 1;
        return array_map(fn($s) => $s * 0.9 / $max, $out);
    }
    private function voice(array &$out, int $start, float $ratio, float $gain, int $rate): void {
        $phase = $phase2 = $hp = $lp = $prev = 0.0;
        for ($i = $start; $i < count($out); $i++) {
            $t = ($i - $start) / $rate;
            $envN = exp(-$t * $this->params['noiseDecay']->current());
            $envT = exp(-$t * $this->params['toneDecay']->current());
            $envC = exp(-$t * $this->attack);
            $sample = 0.0; $noise = 0.0;
            foreach ($this->order as $stage) {
                switch ($stage) {
                    case 'noise':
                        $noise = ($this->noise)();
                        $sample += $noise * $this->params['noiseMix']->current() * $envN;
                        break;
                    case 'hp':
                        $k = min(0.999, $this->params['highPass']->current());
                        $hp = $k * ($hp + $noise - $prev); $prev = $noise;
                        $sample += $hp * $this->params['noiseMix']->current() * $envN * 0.5;
                        break;
                    case 'lp':
                        $k = min(0.999, max(0.001, $this->params['lowPass']->current()));
                        $lp += $k * ($sample - $lp);
                        $sample = $lp;
                        break;
                    case 'tone':
                        $freq = max(1, min(20000, $this->params['toneFreq']->at($t) * $ratio));
                        $phase += 2 * M_PI * $freq / $rate;
                        $phase2 += 2 * M_PI * $freq * 1.5 / $rate;
                        $tone = match ($this->shape) {
                            'cowbell' => 0.65 * sin($phase) + 0.35 * sin($phase2),
                            'square' => (fmod($phase, 2 * M_PI) < M_PI ? 1 : -1),
                            default => sin($phase),
                        };
                        $sample += $tone * $this->params['toneMix']->current() * $envT;
                        break;
                    case 'click':
                        $sample += $noise * $this->params['click']->current() * $envC;
                        break;
                    case 'drive':
                        if ($this->shape === 'tanh') $sample = tanh($sample * $this->params['drive']->current());
                        break;
                }
            }
            $out[$i] += $gain * $sample;
        }
    }
    public function saveWav(string $path, int $rate = 44100): void {
        $samples = $this->render($rate);
        $data = '';
        foreach ($samples as $s) $data .= pack('v', (int)(max(-1, min(1, $s)) * 32767));
        file_put_contents($path, "RIFF".pack('V', 36 + strlen($data))."WAVEfmt ".pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16).'data'.pack('V', strlen($data)).$data);
        thumbnail($this, preg_replace('/\.wav$/', '.png', $path), $samples);
    }
    public function jsonSerialize(): array {
        return ['name'=>$this->name,'noise'=>$this->noise,'shape'=>$this->shape,'order'=>$this->order,
            'gap'=>$this->gap,'dubRatio'=>$this->dubRatio,'attack'=>$this->attack,'params'=>$this->params];
    }
    public static function from(array $d): self {
        $params = [];
        foreach ($d['params'] as $k => $v) $params[$k] = Param::from($v);
        return new self($d['name'], Noise::from($d['noise']), $d['shape'] ?? 'sine',
            $d['order'] ?? ['click','noise','hp','tone','lp','drive'],
            (float)($d['gap'] ?? 0), (float)($d['dubRatio'] ?? 1), (float)($d['attack'] ?? 80), $params);
    }
}

function thumbnail(Hit $hit, string $png, array $samples): void {
    if (!function_exists('imagecreatetruecolor')) return;
    $w = 320; $h = 96;
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 12, 14, 18));
    $keys = array_keys($hit->params);
    $bw = (int)($w / max(1, count($keys)));
    foreach ($keys as $i => $key) {
        $p = $hit->params[$key];
        $lo = min($p->points); $hi = max($p->points);
        $norm = count($p->points) === 1 ? 0.5 : ($p->current() - $lo) / max(1e-6, $hi - $lo);
        $bh = (int)(70 * max(0, min(1, $norm)));
        $hue = crc32($hit->name.$key) & 0xff;
        $col = imagecolorallocate($im, 40 + ($hue % 180), 80 + ($i * 20 % 120), 140);
        imagefilledrectangle($im, $i * $bw + 2, 74 - $bh, ($i + 1) * $bw - 2, 74, $col);
    }
    $wave = imagecolorallocate($im, 220, 220, 210);
    $step = max(1, (int)(count($samples) / $w));
    for ($x = 0; $x < $w; $x++) imagesetpixel($im, $x, 86 - (int)($samples[min(count($samples) - 1, $x * $step)] * 8), $wave);
    imagepng($im, $png);
    imagedestroy($im);
}

final class Bank {
    public array $hits = [];
    public function add(Hit $h): void { $this->hits[$h->name] = $h; }
    public function resolve(string $q): Hit {
        foreach ($this->hits as $n => $h) if (strcasecmp($n, $q) === 0) return $h;
        foreach ($this->hits as $n => $h) if (stripos($n, $q) === 0) return $h;
        throw new InvalidArgumentException("No hit $q");
    }
    public function save(string $dir): void {
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        foreach ($this->hits as $h) file_put_contents("$dir/".strtolower($h->name).".json", json_encode($h, JSON_PRETTY_PRINT));
    }
    public function load(string $dir): void {
        foreach (glob("$dir/*.json") ?: [] as $f) $this->add(Hit::from(json_decode(file_get_contents($f), true)));
    }
}

final class Permuter {
    public function run(Hit $hit, array $keys, string $dir, callable $progress): int {
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $combos = [[]];
        foreach ($keys as $key) {
            $next = [];
            foreach ($combos as $c) foreach ($hit->params[$key]->points as $p) $next[] = $c + [$key => $p];
            $combos = $next;
        }
        foreach ($combos as $i => $combo) {
            $copy = unserialize(serialize($hit));
            foreach ($combo as $key => $point) $copy->params[$key] = new Param([$point], $copy->params[$key]->curve, $point);
            $file = $hit->name.'-'.implode('-', array_map(fn($k,$v)=>"$k$v", array_keys($combo), $combo)).'.wav';
            $copy->saveWav("$dir/$file");
            $progress($i + 1, count($combos), $file);
        }
        return count($combos);
    }
}

function invent(Bank $bank, string $model = 'llama3.2', string $endpoint = 'http://127.0.0.1:11434/api/chat'): Hit {
    $catalog = [];
    foreach ($bank->hits as $h) $catalog[] = $h->jsonSerialize();
    $prompt = "Design one new percussion module as JSON only. Schema: name, noise{kind,seed}, shape, order, gap, dubRatio, attack, params. "
        ."noise.kind is white|pink|brown|blue|violet|bit. shape is sine|tanh|square|cowbell. "
        ."order uses click,noise,hp,lp,tone,drive. Params are {value}, {range:[min,current,max]}, or {seed,curve}. "
        ."curve may use time, freq, min, max, pow, sin, exp. Do not copy an existing name. No markdown.\n"
        .json_encode($catalog);
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'timeout' => 120,
        'content' => json_encode(['model'=>$model,'stream'=>false,'messages'=>[['role'=>'user','content'=>$prompt]]]),
    ]]);
    $raw = file_get_contents($endpoint, false, $ctx);
    if ($raw === false) throw new RuntimeException('AI endpoint did not respond.');
    $text = json_decode($raw, true)['message']['content'] ?? '';
    if (!preg_match('/\{.*\}/s', $text, $m)) throw new RuntimeException('AI did not return JSON.');
    $hit = Hit::from(json_decode($m[0], true));
    $bank->add($hit);
    $bank->save('hits');
    return $hit;
}

function hit(string $name, string $noise, string $shape, array $order, array $dur, array $freq, string $curve, array $rest, float $gap=0, float $dub=1, float $attack=80): Hit {
    [$nd,$td,$nm,$tm,$hp,$lp,$click,$drive] = $rest;
    return new Hit($name, new Noise($noise), $shape, $order, $gap, $dub, $attack, [
        'duration'=>new Param($dur), 'toneFreq'=>new Param([$freq[1]], $curve, $freq[1]),
        'noiseDecay'=>new Param($nd), 'toneDecay'=>new Param($td),
        'noiseMix'=>new Param($nm), 'toneMix'=>new Param($tm),
        'highPass'=>new Param($hp), 'lowPass'=>new Param($lp),
        'click'=>new Param($click), 'drive'=>new Param($drive),
    ]);
}

function seedBank(): Bank {
    $chain = ['click','noise','hp','tone','lp','drive'];
    $bank = new Bank();
    $bank->add(hit('Kick','brown','tanh',$chain,[0.12,0.45,1.2],[35,70,180],'freq*(1-0.72*min(time/0.05,1))+28',[[8,40,120],[4,10,30],[0,0.18,0.6],[0.4,0.9,1.5],[0,0.2,0.6],[0.05,0.4,0.9],[0,0.25,0.8],[1,1.6,3]]));
    $bank->add(hit('SubKick','brown','tanh',$chain,[0.3,0.8,2.0],[25,42,70],'freq*(1-0.5*min(time/0.08,1))+20',[[6,20,60],[3,7,20],[0,0.1,0.4],[0.6,1,1.4],[0,0.1,0.4],[0.02,0.2,0.5],[0,0.1,0.4],[1,1.8,3]]));
    $bank->add(hit('Snare','white','sine',$chain,[0.08,0.35,0.9],[120,190,400],'freq*(1-0.35*min(time/0.08,1))',[[8,28,80],[4,12,40],[0.2,0.55,1.2],[0.1,0.45,1],[0.4,0.86,0.97],[0.2,0.7,0.95],[0,0.25,0.8],[1,1.2,2]]));
    $bank->add(hit('Rimshot','white','sine',$chain,[0.02,0.06,0.2],[400,900,2500],'freq',[[20,80,200],[10,45,120],[0.1,0.45,1],[0.3,0.7,1.2],[0.6,0.9,0.98],[0.3,0.8,0.97],[0.1,0.4,1],[1,1.3,2]],0,1,120));
    $bank->add(hit('Clap','pink','sine',$chain,[0.08,0.28,0.7],[600,1400,4000],'freq',[[12,36,90],[8,40,100],[0.4,0.85,1.5],[0,0.08,0.4],[0.7,0.94,0.98],[0.3,0.8,0.97],[0.1,0.3,0.9],[1,1.1,2]],0.012,1,50));
    $bank->add(hit('Hat','blue','sine',['click','noise','hp','lp'],[0.02,0.08,0.25],[2000,6000,14000],'freq',[[20,70,200],[20,90,200],[0.5,0.95,1.6],[0,0.04,0.2],[0.85,0.97,0.99],[0.4,0.8,0.97],[0.1,0.25,0.8],[1,1,2]],0,1,40));
    $bank->add(hit('OpenHat','violet','sine',$chain,[0.1,0.35,1.1],[1500,5000,12000],'freq',[[4,14,40],[4,18,50],[0.4,0.9,1.5],[0,0.05,0.3],[0.8,0.96,0.99],[0.3,0.75,0.95],[0,0.2,0.7],[1,1,2]]));
    $bank->add(hit('Ride','violet','sine',$chain,[0.2,0.7,2.0],[2000,4500,10000],'freq',[[3,8,24],[3,10,30],[0.5,0.9,1.4],[0.05,0.2,0.5],[0.75,0.93,0.98],[0.2,0.6,0.9],[0,0.15,0.5],[1,1,2]]));
    $bank->add(hit('Crash','white','sine',$chain,[0.4,1.4,3.5],[1500,3500,9000],'freq',[[2,5,16],[2,6,18],[0.6,1,1.6],[0,0.08,0.3],[0.7,0.92,0.98],[0.2,0.55,0.9],[0.1,0.3,0.8],[1,1.2,2]]));
    $bank->add(hit('Tom','pink','tanh',$chain,[0.1,0.4,1.2],[70,110,220],'freq*(1-0.28*min(time/0.06,1))',[[6,22,60],[3,8,24],[0,0.2,0.7],[0.4,0.85,1.4],[0.1,0.4,0.8],[0.1,0.5,0.85],[0,0.25,0.7],[1,1.6,3]]));
    $bank->add(hit('FloorTom','brown','tanh',$chain,[0.15,0.55,1.6],[45,75,140],'freq*(1-0.3*min(time/0.07,1))',[[5,16,50],[2,6,18],[0,0.18,0.6],[0.5,0.9,1.4],[0,0.3,0.7],[0.05,0.35,0.7],[0,0.2,0.6],[1,1.7,3]]));
    $bank->add(hit('Conga','pink','tanh',$chain,[0.08,0.3,0.8],[140,220,400],'freq*(1-0.2*min(time/0.05,1))',[[8,24,70],[4,10,30],[0.05,0.25,0.7],[0.5,0.85,1.3],[0.2,0.5,0.85],[0.15,0.55,0.85],[0,0.3,0.8],[1,1.4,2.5]]));
    $bank->add(hit('Cowbell','white','cowbell',$chain,[0.08,0.4,1.2],[400,587,1200],'freq',[[3,10,30],[2,7,20],[0,0.05,0.3],[0.4,0.8,1.3],[0.2,0.5,0.85],[0.15,0.6,0.9],[0,0.2,0.6],[1,1.2,2]]));
    $bank->add(hit('Woodblock','white','square',$chain,[0.02,0.07,0.2],[500,900,2000],'freq',[[20,70,180],[8,30,80],[0.05,0.2,0.6],[0.5,0.85,1.3],[0.4,0.75,0.95],[0.2,0.6,0.9],[0.2,0.5,1],[1,1.2,2]],0,1,100));
    $bank->add(hit('Shaker','pink','sine',['noise','hp','lp'],[0.05,0.18,0.6],[800,3000,8000],'freq',[[8,24,70],[8,40,100],[0.5,0.92,1.5],[0,0.02,0.15],[0.7,0.93,0.98],[0.3,0.7,0.95],[0,0.15,0.5],[1,1,1]]));
    $bank->add(hit('Tambourine','blue','sine',$chain,[0.1,0.4,1.2],[1500,4000,10000],'freq',[[6,16,50],[6,20,60],[0.5,0.9,1.5],[0.05,0.15,0.4],[0.75,0.94,0.98],[0.3,0.7,0.95],[0.1,0.35,0.9],[1,1.1,2]],0.02,1.2,40));
    $bank->add(hit('Snap','white','sine',$chain,[0.02,0.09,0.25],[800,1800,5000],'freq',[[20,60,160],[10,40,100],[0.3,0.7,1.3],[0.1,0.4,0.9],[0.7,0.92,0.98],[0.3,0.75,0.95],[0.2,0.55,1],[1,1.3,2]],0,1,90));
    $bank->add(hit('Blip','white','sine',$chain,[0.05,0.2,0.6],[200,880,4000],'freq*pow(2,-time*2)',[[8,30,80],[3,9,30],[0,0.02,0.2],[0.4,1,1.5],[0,0.3,0.8],[0.1,0.5,0.9],[0,0.2,0.6],[1,1.1,2]]));
    $bank->add(hit('Zap','blue','sine',$chain,[0.08,0.3,0.8],[200,1500,8000],'freq*pow(2,-time*6)',[[6,20,60],[4,12,40],[0.1,0.4,1],[0.4,0.8,1.4],[0.3,0.7,0.95],[0.15,0.5,0.9],[0.1,0.3,0.8],[1,1.4,2.5]]));
    $bank->add(hit('Laser','violet','sine',$chain,[0.15,0.5,1.4],[100,600,4000],'freq*pow(2,time*3)',[[4,12,40],[3,8,24],[0.1,0.35,0.9],[0.5,0.9,1.4],[0.2,0.6,0.9],[0.1,0.45,0.85],[0,0.2,0.6],[1,1.3,2.5]]));
    $bank->add(hit('Bass','brown','tanh',$chain,[0.2,0.6,2.0],[30,55,120],'freq',[[4,12,40],[2,6,20],[0,0.08,0.3],[0.6,1,1.5],[0,0.15,0.5],[0.04,0.25,0.6],[0,0.1,0.4],[1,1.8,3]]));
    $bank->add(hit('Pluck','white','sine',$chain,[0.1,0.4,1.5],[80,440,2000],'freq',[[6,20,60],[2,6,20],[0,0.05,0.25],[0.5,0.9,1.4],[0.1,0.4,0.8],[0.1,0.45,0.85],[0.1,0.4,1],[1,1.2,2]],0,1,30));
    $bank->add(hit('Heartbeat','brown','tanh',$chain,[0.4,0.9,2.2],[30,55,90],'freq*(1-0.65*min(time/0.04,1))+28',[[6,18,50],[3,8,22],[0,0.22,0.6],[0.5,0.85,1.3],[0,0.25,0.6],[0.05,0.3,0.7],[0,0.2,0.6],[1,1.6,3]],0.18,1.35));
    $bank->add(hit('Tick','bit','square',['click','hp'],[0.005,0.02,0.08],[1000,4000,12000],'freq',[[40,120,300],[20,80,200],[0.2,0.6,1.2],[0.2,0.5,1],[0.7,0.95,0.99],[0.4,0.8,0.97],[0.3,0.7,1.2],[1,1,2]],0,1,200));
    $bank->add(hit('Sweep','pink','sine',$chain,[0.3,1.0,3.0],[80,400,4000],'freq*pow(2,time*2)',[[2,6,20],[2,5,16],[0.2,0.6,1.2],[0.3,0.7,1.2],[0.2,0.6,0.9],[0.1,0.4,0.8],[0,0.15,0.5],[1,1.2,2]]));
    return $bank;
}

final class Composition {
    public function __construct(
        public array $hits,
        public float $bpm = 90,
        public int $beats = 8,
        public float $root = 55,
        public array $ratios = [1, 1, 3/2, 1, 2, 1, 3/2, 1]
    ) {}

    public function render(int $rate = 44100): array {
        $step = $rate * 60 / $this->bpm;
        $n = (int)($step * $this->beats);
        $mix = array_fill(0, $n, 0.0);
        foreach ($this->ratios as $beat => $ratio) {
            $hit = $this->hits[$beat % count($this->hits)];
            $copy = unserialize(serialize($hit));
            $copy->params['toneFreq'] = new Param([$this->root * $ratio], $copy->params['toneFreq']->curve, $this->root * $ratio);
            $start = (int)($beat * $step);
            foreach ($copy->render($rate) as $i => $s) {
                if ($start + $i >= $n) break;
                $mix[$start + $i] += $s * 0.7;
            }
        }
        $max = max(array_map('abs', $mix)) ?: 1;
        return array_map(fn($s) => $s * 0.9 / $max, $mix);
    }

    public function save(string $stem, int $rate = 44100): array {
        if (!is_dir('exports')) mkdir('exports', 0777, true);
        $samples = $this->render($rate);
        $data = '';
        foreach ($samples as $s) $data .= pack('v', (int)(max(-1, min(1, $s)) * 32767));
        $wav = "exports/$stem.wav";
        file_put_contents($wav, "RIFF".pack('V', 36 + strlen($data))."WAVEfmt ".pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16).'data'.pack('V', strlen($data)).$data);
        $png = "exports/$stem.png";
        $this->picture($png, $samples);
        return [$wav, $png];
    }

    private function picture(string $png, array $samples): void {
        if (!function_exists('imagecreatetruecolor')) return;
        $im = imagecreatetruecolor(320, 96);
        imagefilledrectangle($im, 0, 0, 319, 95, imagecolorallocate($im, 12, 14, 18));
        $ink = imagecolorallocate($im, 220, 200, 140);
        $step = max(1, (int)(count($samples) / 320));
        for ($x = 0; $x < 320; $x++) imagesetpixel($im, $x, 48 - (int)($samples[min(count($samples) - 1, $x * $step)] * 40), $ink);
        imagepng($im, $png);
        imagedestroy($im);
    }
}

function harmonicRatios(int $beats): array {
    $scale = [1, 9/8, 5/4, 4/3, 3/2, 5/3, 15/8, 2];
    return array_map(fn($i) => $scale[$i % count($scale)], range(0, $beats - 1));
}

if (PHP_SAPI === 'cli' && realpath($argv[0]) === __FILE__) {
    $bank = seedBank();
    if (!is_dir('hits')) $bank->save('hits');
    else { $loaded = new Bank(); $loaded->load('hits'); if ($loaded->hits) $bank = $loaded; }
    $cmd = $argv[1] ?? 'help';
    match ($cmd) {
        'list' => array_map(fn($h) => print($h->name.PHP_EOL), $bank->hits) || true,
        'sow' => $bank->resolve($argv[2])->saveWav($argv[3] ?? strtolower($argv[2]).'.wav') xor print("wrote\n"),
        'export' => $bank->save('hits') xor print("exported\n"),
        'order' => print(implode(' ', $bank->resolve($argv[2])->order).PHP_EOL),
        'invent' => print(invent($bank, $argv[2] ?? 'llama3.2')->name." imported\n"),
        'permute' => (new Permuter())->run($bank->resolve($argv[2]), explode(',', $argv[3] ?? 'duration'), $argv[4] ?? 'out', fn($d,$t,$f) => print("$d/$t $f\n")),
        default => print("list | sow <hit> [file] | export | order <hit> | invent [model] | permute <hit> key,key [dir]\n"),
    };
}