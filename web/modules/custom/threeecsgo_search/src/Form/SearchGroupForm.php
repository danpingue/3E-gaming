<?php
namespace Drupal\threeecsgo_search\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

/**
 * Class SearchGroupForm.
 *
 * @package Drupal\threeecsgo_search\Form
 */
class SearchGroupForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'search_group_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['group_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Group name'),
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (empty($form_state->getValue('group_name'))) {
      $form_state->setErrorByName('group_name', $this->t('Not blank'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $group_name = $form_state->getValue('group_name');

    $url_xml_group = "http://steamcommunity.com/groups/" . $group_name . "/memberslistxml/";
    $xml_content = file_get_contents($url_xml_group);
    $group_data_xml = simplexml_load_string($xml_content);
    $json = json_encode($group_data_xml);
    $group_data = json_decode($json, TRUE);

    if ($json != "false") {

      $members = $group_data['members']['steamID64'];

      foreach ($members as &$steamid) {
        $url_api_steam_1 = "https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/?key=9B2266D26FF1EEA14F77DFA355BF8FFB&steamids=" . $steamid;
        $content_url_1 = file_get_contents($url_api_steam_1);
        $json_steam_data = json_decode($content_url_1);

        $personaname = $json_steam_data->response->players[0]->personaname;

        $username_drupal = strtolower(str_replace(' ', '', preg_replace('([^A-Za-z0-9])', '', $personaname)));

        $user_register = user_load_by_name($username_drupal);

        if ($user_register == NULL) {
          $realname = $json_steam_data->response->players[0]->realname;
          $primaryclanid = $json_steam_data->response->players[0]->primaryclanid;
          $avatar = $json_steam_data->response->players[0]->avatarfull;
          $tmp = 'public://tmp/avatar.jpeg';
          file_put_contents($tmp, file_get_contents($avatar));

          $url_api_steam_2 = "http://api.steampowered.com/ISteamUserStats/GetUserStatsForGame/v0002/?appid=730&key=9B2266D26FF1EEA14F77DFA355BF8FFB&steamid=" . $steamid;
          $content_url_2 = file_get_contents($url_api_steam_2);
          $json_steam_stats = json_decode($content_url_2);

          $stats = $json_steam_stats->playerstats->stats;

          // Create user object.
          $user = User::create();

          //Mandatory settings
          //$user->setPassword("password");
          $user->enforceIsNew();
          //$user->setEmail("email");
          $user->setUsername($username_drupal);
          //$user->addRole('authenticated');
          $user->{'steamid'}->setValue($steamid);
          $user->{'personaname'}->setValue($personaname);
          $user->{'primaryclanid'}->setValue($primaryclanid);

          if ($realname != NULL) {
            $user->{'realname'}->setValue($realname);
          }
          else {
            $user->{'realname'}->setValue('No name');
          }

          // Create file object from a locally copied file.
          $uri = file_unmanaged_copy($avatar, 'public://avatars/' . $steamid . '.jpg', FILE_EXISTS_REPLACE);
          $file = File::Create([
            'uri' => $uri,
          ]);
          $file->save();

          // Attach file in node.
          $user->avatarfull->setValue([
            'target_id' => $file->id(),
          ]);

          foreach ($stats as $stat) {
            if($stat->name == "total_deaths" or $stat->name == "total_kills" or $stat->name == "total_time_played"
              or $stat->name == "total_wins" or $stat->name == "total_kills_headshot" or $stat->name == "total_mvps"
              or $stat->name == "total_rounds_played" or $stat->name == "total_shots_fired" or $stat->name == "total_shots_hit") {
              $user->{$stat->name}->setValue($stat->value);
            }
          }

          // Create settings node
          $settings = Node::create([
            'title' => "Settings of " . $user->getUsername(),
            'type' => 'settings',
            'status' => 1,
          ]);
          $settings->{'dpi'}->setValue(0);
          $settings->{'hz'}->setValue(0);
          $settings->{'mouse_acceleration'}->setValue(FALSE);
          $settings->{'raw_input'}->setValue(FALSE);
          $settings->{'sensitivity'}->setValue(0);
          $settings->{'windows_sensitivity'}->setValue(0);
          $settings->{'zoom_sensitivity'}->setValue(0);
          $settings->save();

          $user->{'setting'}->setValue($settings->id());
          $user->activate();
          $user->save();

          $user_register = user_load_by_name($username_drupal);

          $settings->{'owner_settings'}->setValue($user_register->id());
          $settings->save();

          //$this->create_inventory($username_drupal);
        }
      }

      $form_state->setRedirect('view.players.all', ['clanid' => $group_data['groupID64']]);
    } else {
      global $base_url;
      $form_state->setRedirectUrl(Url::fromUri($base_url));
    }
  }

  public function create_inventory($username_drupal) {
    $user = user_load_by_name($username_drupal);

    $url_api_steam_3 = "http://steamcommunity.com/profiles/76561198224522144/inventory/json/730/2";
    $content_url_3 = file_get_contents($url_api_steam_3);
    $json_steam_inventory = json_decode($content_url_3);
    $inventory = $json_steam_inventory->rgDescriptions;

    foreach ($inventory as $article) {
      if (strpos(strtoupper($article->market_name), 'CAJA') !== false) {
        $node_inventory = Node::create([
          'title' => $user->getUsername() . " - " . $article->market_name,
          'type' => 'article',
          'status' => 1,
        ]);

        // Create file object from a locally copied file.
        $uri = file_unmanaged_copy("https://steamcommunity-a.akamaihd.net/economy/image/" . $article->icon_url, 'public://inventory/' . $article->market_name . '.jpg', FILE_EXISTS_REPLACE);
        $file = File::Create([
          'uri' => $uri,
        ]);
        $file->save();

        // Attach file in node.
        $node_inventory->image_article->setValue([
          'target_id' => $file->id(),
        ]);

        $node_inventory->{'owner_article'}->setValue($user->id());

        $node_inventory->save();

        $user->{'inventory'}->setValue($node_inventory->id());

        $user->save();

        break;
      }
    }
  }
}
