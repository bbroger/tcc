<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Canal extends CI_Controller {
	
	public function __construct() 
    {
        parent::__construct();
        
        // Carregando model
        $this->load->model('Canal_Model');      
    }
	
	/**
	 * Página principal do canal
	 *
	 * @param string $canal - nome do canal
	 * @return void
	 */
	public function index($canal)
    {
        if (!$this->login->verificar()) exit;
		
		// Definindo tecnologia utilizada
		$data['tecnologia'] = $this->session->userdata('tecnologia');

		// Selecionando canal
		$data['canal'] = $this->Canal_Model->buscar_por_nome($canal);
		$data['usuario'] = $this->login->usuario();
		
		// Se não existir
		if (empty($data['canal']))
			show_error('O canal não está registrado');
		
		// Assets
		$this->css = array('canal.css');
		$this->js = array('canal.js', "{$data['tecnologia']}.js", 'lib/sprintf.js', 'lib/jquery.dateFormat.js');
		
		// View
		$this->layout->view("canal/index", $data);
		
    }
	
	/**
	 * Registra a entrada do usuário no canal
	 *
	 * @param string $canal - nome do canal
	 * @return void
	 */
	public function entrar($canal)
	{
		if (!$this->login->verificar()) exit;
		
		// Selecionando canal
		$canal = $this->Canal_Model->buscar_por_nome($canal);
		
		// Se não existir
		if (empty($canal))
			exit;
		
		// Carregando
		$this->load->model('Online_Model');
		
		// Entrando
		$this->Online_Model->entrar($canal, $this->login->usuario());
	}
	
	/**
	 * Registra a saída do usuário no canal
	 *
	 * @param string $canal - nome do canal
	 * @return void
	 */
	public function sair($canal)
	{
		if (!$this->login->verificar()) exit;
		
		// Selecionando canal
		$canal = $this->Canal_Model->buscar_por_nome($canal);
		
		// Se não existir
		if (empty($canal))
			exit;
		
		// Carregando
		$this->load->model('Online_Model');
		
		// Entrando
		$this->Online_Model->sair($canal, $this->login->usuario());
	}
	
	/**
	 * Atualiza o canal através de 'Short Polling (SP)' 
	 *
	 * @param string $canal - nome do canal
	 * @return void
	 */
	public function sp_atualizar($canal)
	{
		if (!$this->login->verificar()) exit;

		// Selecionando canal
		$canal = $this->Canal_Model->buscar_por_nome($canal);
		
		// Se não existir
		if (empty($canal))
			exit;
		
		// Carregando
		$this->load->model(array('Online_Model', 'Mensagem_Model'));
		
		// Atualizando status
		$this->Online_Model->atualizar($canal, $this->login->usuario());

		$data = array();
		$data['mensagem'] = $this->Mensagem_Model->buscar_por_canal($canal['nome'], $this->input->post('maior_que'));
		$data['usuario'] = $this->Online_Model->buscar_por_canal($canal['nome']);
		
		// Retornando dados
		echo json_encode($data);

	}
	
	/**
	 * Atualiza o canal através de 'Long Polling (LP)'
	 *
	 * @param string $canal - nome do canal
	 * @return void
	 */
	public function lp_atualizar($canal)
	{
		if (!$this->login->verificar()) exit;
		
		// Desativando tempo limite do script
		set_time_limit(0);
		
		// Selecionando canal
		$canal = $this->Canal_Model->buscar_por_nome($canal);
		
		// Se não existir
		if (empty($canal)) exit;
		
		// Carregando
		$this->load->model(array('Online_Model', 'Mensagem_Model'));
		
		// Usuários online para comparação
		$usuario_online_atual = explode(',', $this->input->post('usuario_online'));
		
		// Status do usuário no canal
		$status = null;
		
		do
		{
			// Iniciando vetores
			$data = array();
			$usuario_online = array();
			
			// Atualizando status
			$status = $this->Online_Model->atualizar($canal, $this->login->usuario());
			
			// Buscando mensagens e usuários
			$data['mensagem'] = $this->Mensagem_Model->buscar_por_canal($canal['nome'], $this->input->post('maior_que'));
			$data['usuario'] = $this->Online_Model->buscar_por_canal($canal['nome']);
			
			// Alocando usuários online para comparação
			foreach ($data['usuario'] as $usuario)
				$usuario_online[] = $usuario['usuario']['login'];

			// Se houver mensagens ou alguma modificação nos usuários
			if ((!empty($data['mensagem'])) or (sizeof(array_diff($usuario_online, $usuario_online_atual)) > 0))
			{
				// Retornando dados
				echo json_encode($data);
				break;
			}
			else 
			{
				// Aguardando próxima busca
				sleep(3);
				continue;
			}

		} while(!is_null($status));

	}
	
	
	/**
	 * Atualiza o canal através de 'Server-sent Event (SSE)'
	 *
	 * @param string $canal - nome do canal
	 * @return void
	 */
	public function sse_atualizar($canal)
	{
		if (!$this->login->verificar()) exit;
		
		// Cabeçalhos
        header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache');
			
		// Desativando tempo limite do script
		set_time_limit(0);

		// Selecionando canal
		$canal = $this->Canal_Model->buscar_por_nome($canal);
		
		// Se não existir
		if (empty($canal)) exit;
		
		// Carregando
		$this->load->model(array('Online_Model', 'Mensagem_Model'));

		$usuario_online_atual = array();
		$ultima_mensagem = null;
		$i = 0;
		$c = 0;
		
		// Status do usuário no canal
		$status = null;

		do
		{
			// Iniciando vetores
			$data = array();
			$usuario_online = array();
			
			// Atualizando status
			$status = $this->Online_Model->atualizar($canal, $this->login->usuario());
			
			// Buscando mensagens e usuários
			$data['usuario'] = $this->Online_Model->buscar_por_canal($canal['nome']);
			$data['mensagem'] = $this->Mensagem_Model->buscar_por_canal($canal['nome'], $ultima_mensagem->{'$id'});
			$c = count($data['mensagem']);
			
			// Alocando usuários online para comparação
			foreach ($data['usuario'] as $usuario)
				$usuario_online[] = $usuario['usuario']['login'];
			
			// Se houver mensagens ou alguma modificação nos usuários
			if ((!empty($data['mensagem'])) or (sizeof(array_diff($usuario_online, $usuario_online_atual)) > 0))
			{
				// Alocando última mensagem
				if ($c > 0)
					$ultima_mensagem = $data['mensagem'][$c - 1]['_id'];
				
				// Definindo usuários
				$usuario_online_atual = $usuario_online;
				
				// Resposta
	            echo "id: {$i}" . PHP_EOL; 
				echo 'data: ' . json_encode($data) . PHP_EOL;
	
				$i++;
				
				// Finalizando dados
	            echo PHP_EOL;
	
	            // Enviando saída
	            ob_flush();
				flush();
			
			}
	
            // Aguardando próximo loop
            sleep(3);
			
		} while (!is_null($status));

	}
	
}

/* End of file canal.php */
/* Location: ./application/controllers/canal.php */